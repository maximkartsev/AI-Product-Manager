<?php

namespace App\Services\LoadTesting;

use App\Models\LoadTestRun;
use App\Models\LoadTestStage;
use Illuminate\Support\Collection;

class LoadTestRunnerService
{
    public function __construct(
        private readonly LoadTestStageRatePlanner $ratePlanner,
        private readonly LoadTestSubmissionService $submissionService,
        private readonly LoadTestFaultInjector $faultInjector,
        private readonly LoadTestMetricsAggregator $metricsAggregator
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(LoadTestRun $run, bool $dryRun = false): array
    {
        $scenario = $run->scenario()->with('stages')->first();
        if (!$scenario) {
            throw new \RuntimeException('Load test run has no scenario.');
        }

        /** @var Collection<int, LoadTestStage> $stages */
        $stages = $scenario->stages()->orderBy('stage_order')->get();
        if ($stages->isEmpty()) {
            throw new \RuntimeException('Scenario has no stages.');
        }

        $metrics = $run->metrics_json ?? [];
        $stageReports = [];
        $totalSubmitted = 0;
        $run->status = 'running';
        $run->started_at = $run->started_at ?: now();
        $run->save();

        foreach ($stages as $stage) {
            $stagePlan = $this->planStage($stage);
            $faultResult = $this->faultInjector->injectForStage($run, $stage);
            $submittedCount = 0;
            $submittedDispatchIds = [];
            $submittedJobIds = [];

            if (!$dryRun && $stagePlan['planned_dispatches'] > 0) {
                $submission = $this->submissionService->submitBatch(
                    run: $run,
                    effectRevisionId: (int) $run->effect_revision_id,
                    executionEnvironmentId: (int) $run->execution_environment_id,
                    inputFileId: (int) data_get($metrics, 'input_file_id', 0),
                    inputPayload: (array) data_get($metrics, 'input_payload', []),
                    count: (int) $stagePlan['planned_dispatches'],
                    actingUserId: (int) ($run->created_by_user_id ?? 0),
                    benchmarkContextId: data_get($metrics, 'benchmark_context_id')
                );
                $submittedCount = (int) ($submission['submitted_count'] ?? 0);
                $submittedDispatchIds = (array) ($submission['dispatch_ids'] ?? []);
                $submittedJobIds = (array) ($submission['job_ids'] ?? []);
            }

            $totalSubmitted += $submittedCount;
            $stageReports[] = [
                'stage_id' => $stage->id,
                'stage_order' => $stage->stage_order,
                'stage_type' => $stage->stage_type,
                'duration_seconds' => $stage->duration_seconds,
                'planned_dispatches' => $stagePlan['planned_dispatches'],
                'avg_target_rps' => $stagePlan['avg_target_rps'],
                'fault' => $faultResult,
                'submitted_count' => $submittedCount,
                'dispatch_ids' => $submittedDispatchIds,
                'job_ids' => $submittedJobIds,
            ];
        }

        $run->completed_at = now();
        $run->status = $dryRun ? 'completed' : ($totalSubmitted > 0 ? 'completed' : 'failed');
        $run->metrics_json = array_merge($metrics, [
            'dry_run' => $dryRun,
            'stages' => $stageReports,
            'total_submitted_dispatches' => $totalSubmitted,
        ]);
        $run->save();

        $run = $this->metricsAggregator->aggregateAndPersist($run);

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'total_submitted_dispatches' => $totalSubmitted,
            'stages' => $stageReports,
            'aggregation' => data_get($run->metrics_json, 'aggregation', []),
        ];
    }

    /**
     * @return array{planned_dispatches: int, avg_target_rps: float}
     */
    private function planStage(LoadTestStage $stage): array
    {
        $duration = max(1, (int) $stage->duration_seconds);
        $carry = 0.0;
        $planned = 0;
        $rpsTotal = 0.0;
        for ($second = 0; $second < $duration; $second++) {
            $tick = $this->ratePlanner->dispatchCountForSecond($stage, $second, $carry);
            $planned += (int) $tick['count'];
            $carry = (float) $tick['carry'];
            $rpsTotal += (float) $tick['target_rps'];
        }

        if ($carry >= 0.5) {
            $planned += 1;
        }

        return [
            'planned_dispatches' => $planned,
            'avg_target_rps' => round($rpsTotal / $duration, 4),
        ];
    }
}

