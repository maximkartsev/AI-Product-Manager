<?php

namespace App\Services\LoadTest;

use App\Models\AiJobDispatch;
use App\Models\LoadTestRun;
use App\Models\LoadTestStage;
use App\Services\LoadTesting\LoadTestFaultInjector;
use App\Services\LoadTesting\LoadTestSubmissionService;
use Illuminate\Support\Collection;

class ScenarioExecutor
{
    public function __construct(
        private readonly ScenarioScheduler $scheduler,
        private readonly LoadTestSubmissionService $submissionService,
        private readonly LoadTestFaultInjector $faultInjector
    ) {
    }

    /**
     * @param Collection<int, LoadTestStage> $stages
     * @return array{
     *   status: string,
     *   total_submitted_dispatches: int,
     *   stages: array<int, array<string, mixed>>,
     *   cancelled: bool
     * }
     */
    public function execute(LoadTestRun $run, Collection $stages, bool $dryRun = false): array
    {
        $metrics = is_array($run->metrics_json) ? $run->metrics_json : [];
        $maxOutstanding = (int) data_get($metrics, 'max_outstanding', 0);
        if ($maxOutstanding <= 0) {
            $maxOutstanding = 1000;
        }

        $stageReports = [];
        $totalSubmitted = 0;
        $cancelled = false;

        foreach ($stages as $stage) {
            $schedule = $this->scheduler->scheduleForStage($stage);
            $faultResult = $this->faultInjector->injectForStage($run, $stage);

            $plannedForStage = array_sum(array_map(
                static fn (array $tick): int => (int) ($tick['count'] ?? 0),
                $schedule
            ));
            $submittedCount = 0;
            $dispatchIds = [];
            $jobIds = [];

            foreach ($schedule as $tick) {
                if ($this->isCancelled($run, $dryRun)) {
                    $cancelled = true;
                    break 2;
                }

                $plannedThisTick = (int) ($tick['count'] ?? 0);
                if ($plannedThisTick <= 0 || $dryRun) {
                    continue;
                }

                $outstanding = $this->currentOutstandingDispatches($run->id);
                $availableSlots = max(0, $maxOutstanding - $outstanding);
                $toSubmit = min($plannedThisTick, $availableSlots);
                if ($toSubmit <= 0) {
                    continue;
                }

                $submission = $this->submissionService->submitBatch(
                    run: $run,
                    effectRevisionId: (int) $run->effect_revision_id,
                    executionEnvironmentId: (int) $run->execution_environment_id,
                    inputFileId: (int) data_get($metrics, 'input_file_id', 0),
                    inputPayload: (array) data_get($metrics, 'input_payload', []),
                    count: $toSubmit,
                    actingUserId: (int) ($run->created_by_user_id ?? 0),
                    benchmarkContextId: data_get($metrics, 'benchmark_context_id')
                );

                $submittedThisTick = (int) ($submission['submitted_count'] ?? 0);
                $submittedCount += $submittedThisTick;
                $dispatchIds = array_merge($dispatchIds, (array) ($submission['dispatch_ids'] ?? []));
                $jobIds = array_merge($jobIds, (array) ($submission['job_ids'] ?? []));
            }

            $totalSubmitted += $submittedCount;
            $stageReports[] = [
                'stage_id' => $stage->id,
                'stage_order' => $stage->stage_order,
                'stage_type' => $stage->stage_type,
                'duration_seconds' => $stage->duration_seconds,
                'planned_dispatches' => $plannedForStage,
                'avg_target_rps' => $this->averageTargetRps($schedule),
                'fault' => $faultResult,
                'submitted_count' => $submittedCount,
                'dispatch_ids' => array_values($dispatchIds),
                'job_ids' => array_values($jobIds),
            ];
        }

        return [
            'status' => $cancelled ? 'cancelled' : ($dryRun || $totalSubmitted > 0 ? 'completed' : 'failed'),
            'total_submitted_dispatches' => $totalSubmitted,
            'stages' => $stageReports,
            'cancelled' => $cancelled,
        ];
    }

    private function currentOutstandingDispatches(int $runId): int
    {
        return AiJobDispatch::query()
            ->where('load_test_run_id', $runId)
            ->whereIn('status', ['queued', 'leased'])
            ->count();
    }

    private function isCancelled(LoadTestRun $run, bool $dryRun): bool
    {
        if ($dryRun) {
            return false;
        }

        $fresh = LoadTestRun::query()->find($run->id);

        return (string) ($fresh?->status ?? '') === 'cancelled';
    }

    /**
     * @param array<int, array<string, mixed>> $schedule
     */
    private function averageTargetRps(array $schedule): float
    {
        if (empty($schedule)) {
            return 0.0;
        }

        $total = array_sum(array_map(
            static fn (array $tick): float => (float) ($tick['target_rps'] ?? 0.0),
            $schedule
        ));

        return round($total / count($schedule), 4);
    }
}
