<?php

namespace App\Services;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\File;
use App\Models\User;
use App\Models\Video;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudioBlackboxRunnerService
{
    public function __construct(
        private readonly WorkflowPayloadService $workflowPayloadService,
        private readonly TokenLedgerService $tokenLedgerService,
        private readonly RunCostModelService $runCostModelService,
    ) {
    }

    /**
     * @param array<string, mixed> $inputPayload
     * @param array<string, mixed> $costModelInput
     * @return array{
     *   run: EffectTestRun,
     *   job_ids: array<int, int>,
     *   dispatch_ids: array<int, int>,
     *   dispatch_count: int,
     *   cost_report: array<string, mixed>
     * }
     */
    public function run(
        EffectTestRun $run,
        Effect $effect,
        EffectRevision $revision,
        ExecutionEnvironment $environment,
        User $user,
        int $inputFileId,
        array $inputPayload,
        int $count,
        array $costModelInput = []
    ): array {
        $workflow = $this->resolveWorkflow($effect, $revision);
        $this->assertStagingFleetAssignment($workflow->id);

        $inputFile = File::query()->find($inputFileId);
        if (!$inputFile) {
            throw new \RuntimeException('Input file not found.');
        }
        if ((int) $inputFile->user_id !== (int) $user->id) {
            throw new \RuntimeException('Input file ownership mismatch.');
        }

        $runtimeEffect = $this->buildRuntimeEffect($effect, $workflow, $revision);
        [$jobPayload, $workUnits] = $this->preparePayloadAndUnits($runtimeEffect, $inputPayload, $inputFile, $user);

        $tokenCost = (int) ceil((float) $effect->credits_cost);
        $provider = (string) config('services.comfyui.default_provider', 'self_hosted');
        $dispatchStage = 'staging';
        $tenantId = (string) tenant()->getKey();

        $run->status = 'running';
        $run->started_at = now();
        $run->save();

        $jobIds = [];
        $dispatchIds = [];

        for ($i = 0; $i < $count; $i++) {
            $job = DB::connection('tenant')->transaction(function () use (
                $tenantId,
                $user,
                $effect,
                $inputFile,
                $inputPayload,
                $provider,
                $tokenCost,
                $jobPayload,
                $run
            ) {
                $video = Video::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => (int) $user->id,
                    'effect_id' => $effect->id,
                    'original_file_id' => $inputFile->id,
                    'status' => 'queued',
                    'is_public' => false,
                    'input_payload' => $inputPayload,
                ]);

                $job = AiJob::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => (int) $user->id,
                    'effect_id' => $effect->id,
                    'provider' => $provider,
                    'video_id' => $video->id,
                    'input_file_id' => $inputFile->id,
                    'status' => 'queued',
                    'idempotency_key' => 'studio_blackbox_' . (string) Str::uuid(),
                    'requested_tokens' => $tokenCost,
                    'reserved_tokens' => 0,
                    'consumed_tokens' => 0,
                    'input_payload' => $jobPayload,
                ]);

                $this->tokenLedgerService->reserveForJob($job, $tokenCost, [
                    'source' => 'studio_blackbox',
                    'effect_id' => $effect->id,
                    'effect_test_run_id' => $run->id,
                ]);

                return $job->fresh();
            });

            $dispatch = AiJobDispatch::query()->create([
                'tenant_id' => $tenantId,
                'tenant_job_id' => $job->id,
                'provider' => $provider,
                'workflow_id' => $workflow->id,
                'stage' => $dispatchStage,
                'status' => 'queued',
                'priority' => 0,
                'attempts' => 0,
                'work_units' => $workUnits['units'] ?? null,
                'work_unit_kind' => $workUnits['kind'] ?? null,
            ]);

            $jobIds[] = (int) $job->id;
            $dispatchIds[] = (int) $dispatch->id;
        }

        $costReport = $this->runCostModelService->build($this->buildCostModelInput(
            $runtimeEffect,
            $count,
            $costModelInput
        ));

        $run->status = 'queued';
        $run->completed_at = null;
        $run->metrics_json = array_merge($run->metrics_json ?? [], [
            'dispatch_stage' => $dispatchStage,
            'job_ids' => $jobIds,
            'dispatch_ids' => $dispatchIds,
            'cost_report' => $costReport,
        ]);
        $run->save();

        return [
            'run' => $run->fresh(),
            'job_ids' => $jobIds,
            'dispatch_ids' => $dispatchIds,
            'dispatch_count' => count($dispatchIds),
            'cost_report' => $costReport,
        ];
    }

    public function markFailed(EffectTestRun $run, string $message): EffectTestRun
    {
        $run->status = 'failed';
        if (!$run->started_at) {
            $run->started_at = now();
        }
        $run->completed_at = now();
        $run->metrics_json = array_merge($run->metrics_json ?? [], [
            'error' => $message,
        ]);
        $run->save();

        return $run->fresh();
    }

    private function resolveWorkflow(Effect $effect, EffectRevision $revision): Workflow
    {
        $workflowId = $revision->workflow_id ?: $effect->workflow_id;
        if (!$workflowId) {
            throw new \RuntimeException('Effect revision has no workflow.');
        }

        $workflow = Workflow::query()->find($workflowId);
        if (!$workflow) {
            throw new \RuntimeException('Workflow for revision was not found.');
        }

        return $workflow;
    }

    private function assertStagingFleetAssignment(int $workflowId): void
    {
        $hasStagingAssignment = ComfyUiWorkflowFleet::query()
            ->where('workflow_id', $workflowId)
            ->where('stage', 'staging')
            ->exists();

        if (!$hasStagingAssignment) {
            throw new \RuntimeException('Workflow is not available for staging processing.');
        }
    }

    private function buildRuntimeEffect(Effect $effect, Workflow $workflow, EffectRevision $revision): Effect
    {
        $runtime = $effect->replicate();
        $runtime->id = $effect->id;
        $runtime->workflow_id = $workflow->id;
        $runtime->property_overrides = is_array($revision->property_overrides)
            ? $revision->property_overrides
            : ($effect->property_overrides ?? []);
        $runtime->setRelation('workflow', $workflow);

        return $runtime;
    }

    /**
     * @param array<string, mixed> $inputPayload
     * @return array{0: array<string, mixed>, 1: array{units: float, kind: string}}
     */
    private function preparePayloadAndUnits(Effect $effect, array $inputPayload, File $inputFile, User $user): array
    {
        if (!$effect->workflow_id || !$effect->workflow) {
            throw new \RuntimeException('Effect is not configured for processing.');
        }

        $properties = $effect->workflow->properties ?? [];
        $allowed = $this->buildAllowedPropertyMap($properties);
        $userInput = $this->extractUserInput($inputPayload, $allowed, $user);
        $resolvedProps = $this->workflowPayloadService->resolveProperties($effect->workflow, $effect, $userInput);
        $this->assertRequiredProperties($properties, $resolvedProps);

        $payload = $this->workflowPayloadService->buildJobPayload($effect, $resolvedProps, $inputFile);
        $workUnits = $this->workflowPayloadService->computeWorkUnitsFromResolvedProps($effect->workflow, $resolvedProps);

        return [$payload, $workUnits];
    }

    /**
     * @param array<int, mixed> $properties
     * @return array<string, array<string, mixed>>
     */
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

    /**
     * @param array<string, mixed> $inputPayload
     * @param array<string, array<string, mixed>> $allowed
     * @return array<string, mixed>
     */
    private function extractUserInput(array $inputPayload, array $allowed, User $user): array
    {
        $userInput = [];
        foreach ($inputPayload as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                throw new \RuntimeException("Unsupported property: {$key}");
            }

            $prop = $allowed[$key];
            $type = (string) ($prop['type'] ?? 'text');

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

    /**
     * @param array<int, mixed> $properties
     * @param array<string, mixed> $resolvedProps
     */
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

            $type = (string) ($prop['type'] ?? 'text');
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

    /**
     * @param array<string, mixed> $costModelInput
     * @return array<string, mixed>
     */
    private function buildCostModelInput(Effect $effect, int $count, array $costModelInput): array
    {
        $busySeconds = (float) ($effect->last_processing_time_seconds ?: 30);
        if ($busySeconds <= 0) {
            $busySeconds = 30;
        }

        $runCounts = $costModelInput['run_counts'] ?? [1, 10, 100];
        if (!is_array($runCounts)) {
            $runCounts = [1, 10, 100];
        }

        return [
            'startup_seconds' => $costModelInput['startup_seconds'] ?? 120,
            'busy_seconds_per_run' => $costModelInput['busy_seconds_per_run'] ?? $busySeconds,
            'idle_seconds_after_batch' => $costModelInput['idle_seconds_after_batch'] ?? 60,
            'compute_rate_usd_per_second' => $costModelInput['compute_rate_usd_per_second'] ?? 0.01,
            'partner_cost_usd_per_run' => $costModelInput['partner_cost_usd_per_run'] ?? 0,
            'revenue_usd_per_run' => $costModelInput['revenue_usd_per_run'] ?? (float) ($effect->credits_cost ?? 0),
            'run_counts' => $runCounts,
        ];
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
}

