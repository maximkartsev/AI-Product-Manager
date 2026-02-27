<?php

namespace App\Services;

use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\File;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Str;

class StudioBlackboxRunnerService
{
    public function __construct(
        private readonly EffectRunSubmissionService $submissionService,
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
        $this->assertEnvironment($environment);
        $this->assertStagingFleetAssignment($workflow->id, (string) $environment->fleet_slug);

        $inputFile = File::query()->find($inputFileId);
        if (!$inputFile) {
            throw new \RuntimeException('Input file not found.');
        }
        if ((int) $inputFile->user_id !== (int) $user->id) {
            throw new \RuntimeException('Input file ownership mismatch.');
        }

        $runtimeEffect = $this->buildRuntimeEffect($effect, $workflow, $revision);
        [$jobPayload, $workUnits] = $this->submissionService->preparePayloadAndUnits(
            $runtimeEffect,
            $inputPayload,
            $inputFile,
            $user
        );

        $tokenCost = (int) ceil((float) $effect->credits_cost);
        $provider = (string) config('services.comfyui.default_provider', 'self_hosted');
        $dispatchStage = 'staging';

        $run->status = 'running';
        $run->started_at = now();
        $run->save();

        $jobIds = [];
        $dispatchIds = [];

        for ($i = 0; $i < $count; $i++) {
            $submission = $this->submissionService->submitPrepared(
                user: $user,
                effect: $effect,
                idempotencyKey: 'studio_blackbox_' . $run->id . '_' . $i . '_' . (string) Str::uuid(),
                tokenCost: $tokenCost,
                videoId: null,
                inputFileId: (int) $inputFile->id,
                provider: $provider,
                preparedPayload: $jobPayload,
                workUnits: $workUnits['units'] ?? null,
                workUnitKind: $workUnits['kind'] ?? null,
                workflowId: $workflow->id,
                dispatchStage: $dispatchStage,
                priority: 0,
                ledgerMetadata: [
                    'source' => 'studio_blackbox',
                    'effect_id' => $effect->id,
                    'effect_test_run_id' => $run->id,
                ]
            );

            $job = $submission['job'];
            $dispatch = $submission['dispatch'] ?? null;
            if (!$dispatch) {
                throw new \RuntimeException('Failed to enqueue blackbox job dispatch.');
            }

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

    private function assertEnvironment(ExecutionEnvironment $environment): void
    {
        if ($environment->kind !== 'test_asg' || !$environment->is_active) {
            throw new \RuntimeException('Execution environment must be an active test_asg environment.');
        }
    }

    private function assertStagingFleetAssignment(int $workflowId, string $fleetSlug): void
    {
        if (trim($fleetSlug) === '') {
            throw new \RuntimeException('Execution environment must define staging fleet slug.');
        }

        $hasAssignmentForSelectedFleet = ComfyUiWorkflowFleet::query()
            ->where('workflow_id', $workflowId)
            ->where('stage', 'staging')
            ->whereHas('fleet', function ($query) use ($fleetSlug) {
                $query->where('slug', $fleetSlug)
                    ->where('stage', 'staging');
            })
            ->exists();

        if (!$hasAssignmentForSelectedFleet) {
            throw new \RuntimeException('Workflow is not assigned to selected staging fleet.');
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

}

