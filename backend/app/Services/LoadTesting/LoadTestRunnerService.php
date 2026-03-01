<?php

namespace App\Services\LoadTesting;

use App\Models\LoadTestRun;
use App\Models\LoadTestStage;
use App\Services\LoadTest\ScenarioExecutor;
use Illuminate\Support\Collection;

class LoadTestRunnerService
{
    public function __construct(
        private readonly ScenarioExecutor $scenarioExecutor,
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
        $run->status = 'running';
        $run->started_at = $run->started_at ?: now();
        $run->save();

        $execution = $this->scenarioExecutor->execute($run, $stages, $dryRun);
        $run = $run->fresh();

        if ((bool) ($execution['cancelled'] ?? false)) {
            $run->status = 'cancelled';
            $run->completed_at = $run->completed_at ?: now();
        } else {
            $run->status = (string) ($execution['status'] ?? 'failed');
            $run->completed_at = now();
        }

        $totalSubmitted = (int) ($execution['total_submitted_dispatches'] ?? 0);
        $stageReports = (array) ($execution['stages'] ?? []);
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
}

