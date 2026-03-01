<?php

namespace App\Services;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectVariantBinding;
use App\Models\ExecutionEnvironment;
use App\Models\File;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class EffectRunSubmissionService
{
    /**
     * @return array{
     *   runtime_effect: Effect,
     *   workflow_id: int,
     *   dispatch_stage: string,
     *   selected_variant_id: string|null
     * }
     */
    public function buildRuntimeEffectForPublicRun(Effect $effect): array
    {
        $effect->loadMissing('workflow');

        $revision = null;
        if ($effect->published_revision_id) {
            $revision = EffectRevision::query()
                ->where('effect_id', $effect->id)
                ->find((int) $effect->published_revision_id);
        }

        if (!$revision) {
            $revision = app(EffectRevisionService::class)->createSnapshot($effect, null);
            $effect->published_revision_id = $revision->id;
            $effect->save();
        }

        $activeBinding = EffectVariantBinding::query()
            ->where('effect_revision_id', (int) $revision->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        $workflowId = (int) ($activeBinding?->workflow_id ?: ($revision?->workflow_id ?: $effect->workflow_id));
        if ($workflowId <= 0) {
            throw new \RuntimeException('Effect has no configured workflow.');
        }

        $workflow = Workflow::query()->find($workflowId);
        if (!$workflow) {
            throw new \RuntimeException('Effect has no configured workflow.');
        }

        $dispatchStage = (string) ($activeBinding?->stage ?: 'production');
        $environment = null;
        if ($activeBinding?->execution_environment_id) {
            $environment = ExecutionEnvironment::query()->find((int) $activeBinding->execution_environment_id);
        }
        if (!$environment) {
            $environment = app(EffectPublicationService::class)->resolveProductionEnvironmentForEffect(
                $effect,
                $workflow->id
            );
        }
        if (!$environment || !$environment->is_active || (string) $environment->stage !== $dispatchStage) {
            throw new \RuntimeException('Effect is not available for selected runtime routing.');
        }
        if ((int) ($effect->prod_execution_environment_id ?? 0) !== (int) $environment->id) {
            $effect->prod_execution_environment_id = $environment->id;
            $effect->save();
        }

        $runtimeEffect = $effect->replicate();
        $runtimeEffect->id = $effect->id;
        $runtimeEffect->workflow_id = $workflow->id;
        $runtimeEffect->property_overrides = is_array($revision?->property_overrides)
            ? $revision->property_overrides
            : $effect->property_overrides;
        $runtimeEffect->setRelation('workflow', $workflow);

        return [
            'runtime_effect' => $runtimeEffect,
            'workflow_id' => $workflow->id,
            'dispatch_stage' => $dispatchStage,
            'selected_variant_id' => $activeBinding?->variant_id,
        ];
    }

    /**
     * @return array{0: array, 1: array{units: float, kind: string}}
     */
    public function preparePayloadAndUnits(Effect $effect, array $inputPayload, ?File $inputFile, User $user): array
    {
        if (!$effect->workflow_id || !$effect->workflow) {
            throw new \RuntimeException('Effect is not configured for processing.');
        }

        $service = app(WorkflowPayloadService::class);
        $properties = $effect->workflow->properties ?? [];
        $allowed = $this->buildAllowedPropertyMap($properties);
        $userInput = $this->extractUserInput($inputPayload, $allowed, $user);
        $resolvedProps = $service->resolveProperties($effect->workflow, $effect, $userInput);
        $this->assertRequiredProperties($properties, $resolvedProps);
        $payload = $service->buildJobPayload($effect, $resolvedProps, $inputFile);
        $workUnits = $service->computeWorkUnitsFromResolvedProps($effect->workflow, $resolvedProps);

        return [$payload, $workUnits];
    }

    /**
     * @param array<string, mixed> $preparedPayload
     * @param array<string, mixed> $ledgerMetadata
     * @return array{job: AiJob, dispatch: ?AiJobDispatch, already_submitted: bool}
     */
    public function submitPrepared(
        User $user,
        Effect $effect,
        string $idempotencyKey,
        int $tokenCost,
        ?int $videoId,
        ?int $inputFileId,
        array $preparedPayload,
        ?float $workUnits,
        ?string $workUnitKind,
        int $workflowId,
        string $dispatchStage = 'production',
        int $priority = 0,
        array $ledgerMetadata = [],
        ?int $loadTestRunId = null,
        ?string $benchmarkContextId = null
    ): array {
        try {
            $job = DB::connection('tenant')->transaction(function () use (
                $user,
                $effect,
                $idempotencyKey,
                $tokenCost,
                $videoId,
                $inputFileId,
                $preparedPayload,
                $ledgerMetadata
            ) {
                $aiJob = AiJob::query()->create([
                    'user_id' => $user->id,
                    'effect_id' => $effect->id,
                    'video_id' => $videoId,
                    'input_file_id' => $inputFileId,
                    'status' => 'queued',
                    'idempotency_key' => $idempotencyKey,
                    'requested_tokens' => $tokenCost,
                    'reserved_tokens' => 0,
                    'consumed_tokens' => 0,
                    'input_payload' => $preparedPayload,
                ]);

                app(TokenLedgerService::class)->reserveForJob($aiJob, $tokenCost, array_merge([
                    'source' => 'ai_job_submission',
                    'effect_id' => (int) $effect->id,
                ], $ledgerMetadata));

                return $aiJob->fresh();
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'ai_jobs_tenant_idempotency_unique')) {
                $existing = AiJob::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $dispatch = $this->ensureDispatch(
                        $existing,
                        $priority,
                        $workUnits,
                        $workUnitKind,
                        $workflowId,
                        $dispatchStage,
                        $loadTestRunId,
                        $benchmarkContextId
                    );

                    return [
                        'job' => $existing,
                        'dispatch' => $dispatch,
                        'already_submitted' => true,
                    ];
                }
            }

            throw $e;
        }

        $dispatch = $this->ensureDispatch(
            $job,
            $priority,
            $workUnits,
            $workUnitKind,
            $workflowId,
            $dispatchStage,
            $loadTestRunId,
            $benchmarkContextId
        );

        return [
            'job' => $job,
            'dispatch' => $dispatch,
            'already_submitted' => false,
        ];
    }

    public function ensureDispatchForJob(
        AiJob $job,
        int $priority = 0,
        ?float $workUnits = null,
        ?string $workUnitKind = null,
        ?int $workflowId = null,
        string $dispatchStage = 'production',
        ?int $loadTestRunId = null,
        ?string $benchmarkContextId = null
    ): ?AiJobDispatch {
        return $this->ensureDispatch(
            $job,
            $priority,
            $workUnits,
            $workUnitKind,
            $workflowId,
            $dispatchStage,
            $loadTestRunId,
            $benchmarkContextId
        );
    }

    private function buildAllowedPropertyMap(array $properties): array
    {
        $allowed = [];
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            if (empty($prop['user_configurable']) || !empty($prop['is_primary_input'])) {
                continue;
            }
            $key = $prop['key'] ?? null;
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $allowed[$key] = $prop;
        }

        return $allowed;
    }

    private function extractUserInput(array $inputPayload, array $allowed, User $user): array
    {
        $userInput = [];
        foreach ($inputPayload as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                throw new \RuntimeException("Unsupported property: {$key}");
            }
            $prop = $allowed[$key];
            $type = $prop['type'] ?? 'text';
            if ($type === 'text') {
                $normalized = $this->normalizeTextInput($value);
                if ($normalized !== null) {
                    $userInput[$key] = $normalized;
                }
                continue;
            }
            if (in_array($type, ['image', 'video'], true)) {
                $fileId = $this->normalizeFileId($value);
                if (!$fileId) {
                    throw new \RuntimeException("Invalid file id for {$key}.");
                }
                $file = File::query()->find($fileId);
                if (!$file) {
                    throw new \RuntimeException("File not found for {$key}.");
                }
                if ((int) $file->user_id !== (int) $user->id) {
                    throw new \RuntimeException("File ownership mismatch for {$key}.");
                }
                $expiresAt = data_get($file->metadata, 'expires_at');
                if ($expiresAt && now()->gte(\Carbon\Carbon::parse($expiresAt))) {
                    throw new \RuntimeException("File has expired for {$key}.");
                }
                if ($expiresAt) {
                    $metadata = $file->metadata ?? [];
                    unset($metadata['expires_at']);
                    $file->metadata = $metadata;
                    $file->save();
                }
                if (!$this->matchesFileType($file->mime_type, $type)) {
                    throw new \RuntimeException("File type mismatch for {$key}.");
                }
                $userInput[$key] = [
                    'disk' => $file->disk,
                    'path' => $file->path,
                ];
                continue;
            }

            $normalized = $this->normalizeTextInput($value);
            if ($normalized !== null) {
                $userInput[$key] = $normalized;
            }
        }

        return $userInput;
    }

    private function assertRequiredProperties(array $properties, array $resolvedProps): void
    {
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            if (empty($prop['required']) || !empty($prop['is_primary_input'])) {
                continue;
            }
            $key = $prop['key'] ?? null;
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $type = $prop['type'] ?? 'text';
            $value = $resolvedProps[$key] ?? null;
            if ($type === 'text') {
                $text = is_string($value) ? trim($value) : '';
                if ($text === '') {
                    throw new \RuntimeException("Missing required property: {$key}");
                }
                continue;
            }
            if (in_array($type, ['image', 'video'], true)) {
                if (is_array($value)) {
                    $path = $value['path'] ?? null;
                    if (is_string($path) && trim($path) !== '') {
                        continue;
                    }
                } elseif (is_string($value) && trim($value) !== '') {
                    continue;
                }
                throw new \RuntimeException("Missing required property: {$key}");
            }
            if ($value === null || $value === '') {
                throw new \RuntimeException("Missing required property: {$key}");
            }
        }
    }

    private function normalizeTextInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeFileId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;

            return $parsed > 0 ? $parsed : null;
        }
        if (is_numeric($value)) {
            $parsed = (int) $value;

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function matchesFileType(?string $mimeType, string $expectedType): bool
    {
        if (!$mimeType) {
            return false;
        }
        $normalized = strtolower($mimeType);

        return $expectedType === 'image'
            ? str_starts_with($normalized, 'image/')
            : str_starts_with($normalized, 'video/');
    }

    private function ensureDispatch(
        AiJob $job,
        int $priority = 0,
        ?float $workUnits = null,
        ?string $workUnitKind = null,
        ?int $workflowId = null,
        string $dispatchStage = 'production',
        ?int $loadTestRunId = null,
        ?string $benchmarkContextId = null
    ): ?AiJobDispatch {
        try {
            $dispatch = AiJobDispatch::query()->firstOrCreate([
                'tenant_id' => (string) $job->tenant_id,
                'tenant_job_id' => $job->id,
            ], [
                'workflow_id' => $workflowId,
                'load_test_run_id' => $loadTestRunId,
                'benchmark_context_id' => $benchmarkContextId,
                'stage' => $dispatchStage,
                'status' => 'queued',
                'priority' => $priority,
                'attempts' => 0,
                'work_units' => $workUnits,
                'work_unit_kind' => $workUnitKind,
            ]);

            $needsContextUpdate = false;
            if ($loadTestRunId !== null && (int) ($dispatch->load_test_run_id ?? 0) !== $loadTestRunId) {
                $dispatch->load_test_run_id = $loadTestRunId;
                $needsContextUpdate = true;
            }

            if ($benchmarkContextId !== null && (string) ($dispatch->benchmark_context_id ?? '') !== $benchmarkContextId) {
                $dispatch->benchmark_context_id = $benchmarkContextId;
                $needsContextUpdate = true;
            }

            if ($needsContextUpdate) {
                $dispatch->save();
            }

            return $dispatch;
        } catch (\Throwable) {
            $job->status = 'failed';
            $job->error_message = 'Failed to enqueue job for dispatch.';
            $job->completed_at = now();
            $job->save();

            try {
                app(TokenLedgerService::class)->refundForJob($job, ['source' => 'dispatch_enqueue']);
            } catch (\Throwable) {
                // ignore refund failures to avoid masking dispatch errors
            }

            return null;
        }
    }
}

