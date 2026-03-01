<?php

namespace App\Services\LoadTesting;

use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\ExecutionEnvironment;
use App\Models\File;
use App\Models\LoadTestRun;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Services\EffectRunSubmissionService;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;

class LoadTestSubmissionService
{
    public function __construct(
        private readonly EffectRunSubmissionService $submissionService
    ) {
    }

    /**
     * @param array<string, mixed> $inputPayload
     * @return array<string, mixed>
     */
    public function submitBatch(
        LoadTestRun $run,
        int $effectRevisionId,
        int $executionEnvironmentId,
        int $inputFileId,
        array $inputPayload,
        int $count,
        ?int $actingUserId = null,
        ?string $benchmarkContextId = null
    ): array {
        $revision = EffectRevision::query()->find($effectRevisionId);
        if (!$revision) {
            throw new \RuntimeException('Effect revision not found.');
        }

        $effect = Effect::query()->find((int) $revision->effect_id);
        if (!$effect) {
            throw new \RuntimeException('Effect for revision not found.');
        }

        $workflow = Workflow::query()->find((int) ($revision->workflow_id ?: $effect->workflow_id));
        if (!$workflow) {
            throw new \RuntimeException('Workflow for revision not found.');
        }

        $environment = ExecutionEnvironment::query()->find($executionEnvironmentId);
        if (!$environment || !$environment->is_active || $environment->kind !== 'test_asg') {
            throw new \RuntimeException('Execution environment must be an active test_asg environment.');
        }

        $user = $this->resolveUser($run, $actingUserId);
        if (!$user) {
            throw new \RuntimeException('No eligible user found to submit load test jobs.');
        }

        $tenant = Tenant::query()->where('user_id', $user->id)->first();
        if (!$tenant) {
            throw new \RuntimeException('Tenant for load test user was not found.');
        }

        $dispatchIds = [];
        $jobIds = [];
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            $inputFile = File::query()->find($inputFileId);
            if (!$inputFile) {
                throw new \RuntimeException('Input file not found.');
            }
            if ((int) $inputFile->user_id !== (int) $user->id) {
                throw new \RuntimeException('Input file ownership mismatch.');
            }

            $runtimeEffect = $this->buildRuntimeEffect($effect, $workflow, $revision);
            [$preparedPayload, $workUnits] = $this->submissionService->preparePayloadAndUnits(
                $runtimeEffect,
                $inputPayload,
                $inputFile,
                $user
            );

            for ($i = 0; $i < max(1, $count); $i++) {
                $idempotencyKey = sprintf(
                    'studio_load_test_%d_%d_%s',
                    $run->id,
                    $i,
                    (string) Str::uuid()
                );

                $submission = $this->submissionService->submitPrepared(
                    user: $user,
                    effect: $effect,
                    idempotencyKey: $idempotencyKey,
                    tokenCost: 0,
                    videoId: null,
                    inputFileId: $inputFile->id,
                    preparedPayload: $preparedPayload,
                    workUnits: $workUnits['units'] ?? null,
                    workUnitKind: $workUnits['kind'] ?? null,
                    workflowId: $workflow->id,
                    dispatchStage: (string) ($environment->stage ?: 'staging'),
                    priority: 0,
                    ledgerMetadata: [
                        'source' => 'studio_load_test',
                        'load_test_run_id' => $run->id,
                        'effect_revision_id' => $revision->id,
                    ],
                    loadTestRunId: $run->id,
                    benchmarkContextId: $benchmarkContextId
                );

                $job = $submission['job'];
                $dispatch = $submission['dispatch'] ?? null;
                if (!$dispatch) {
                    throw new \RuntimeException('Dispatch creation failed for load test job.');
                }

                $jobIds[] = (int) $job->id;
                $dispatchIds[] = (int) $dispatch->id;
            }
        } finally {
            $tenancy->end();
        }

        return [
            'submitted_count' => count($dispatchIds),
            'dispatch_ids' => $dispatchIds,
            'job_ids' => $jobIds,
            'tenant_id' => (string) $tenant->id,
            'acting_user_id' => (int) $user->id,
        ];
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

    private function resolveUser(LoadTestRun $run, ?int $actingUserId): ?User
    {
        $candidateId = $actingUserId ?: (int) ($run->created_by_user_id ?? 0);
        if ($candidateId > 0) {
            return User::query()->find($candidateId);
        }

        return User::query()->where('is_admin', true)->orderBy('id')->first();
    }
}

