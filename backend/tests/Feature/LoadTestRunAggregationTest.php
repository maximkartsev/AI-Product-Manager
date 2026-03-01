<?php

namespace Tests\Feature;

use App\Models\AiJobDispatch;
use App\Services\LoadTesting\LoadTestMetricsAggregator;
use Carbon\CarbonImmutable;

class LoadTestRunAggregationTest extends StudioLoadTestFeatureTestCase
{
    public function test_aggregation_persists_counts_and_percentiles_on_run(): void
    {
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $scenario = $this->createScenario();
        $startedAt = CarbonImmutable::now()->subSeconds(20)->startOfSecond();
        $completedAt = CarbonImmutable::now()->subSeconds(10)->startOfSecond();

        $run = $this->createRun($scenario, $environment, $revision, [
            'status' => 'completed',
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);

        AiJobDispatch::query()->create([
            'tenant_id' => (string) $this->tenant->id,
            'tenant_job_id' => 101,
            'workflow_id' => $workflow->id,
            'load_test_run_id' => $run->id,
            'stage' => 'test',
            'status' => 'completed',
            'priority' => 0,
            'attempts' => 0,
            'duration_seconds' => 2.2,
            'queue_wait_seconds' => 0.6,
            'processing_seconds' => 1.6,
        ]);

        AiJobDispatch::query()->create([
            'tenant_id' => (string) $this->tenant->id,
            'tenant_job_id' => 102,
            'workflow_id' => $workflow->id,
            'load_test_run_id' => $run->id,
            'stage' => 'test',
            'status' => 'completed',
            'priority' => 0,
            'attempts' => 0,
            'duration_seconds' => 1.4,
            'queue_wait_seconds' => 0.3,
            'processing_seconds' => 1.1,
        ]);

        AiJobDispatch::query()->create([
            'tenant_id' => (string) $this->tenant->id,
            'tenant_job_id' => 103,
            'workflow_id' => $workflow->id,
            'load_test_run_id' => $run->id,
            'stage' => 'test',
            'status' => 'failed',
            'priority' => 0,
            'attempts' => 0,
            'duration_seconds' => 3.8,
            'queue_wait_seconds' => 1.2,
            'processing_seconds' => 2.6,
        ]);

        $fresh = app(LoadTestMetricsAggregator::class)->aggregateAndPersist($run->fresh());

        $this->assertSame(2, (int) $fresh->success_count);
        $this->assertSame(1, (int) $fresh->failure_count);
        $this->assertNotNull($fresh->p95_latency_ms);
        $this->assertNotNull($fresh->queue_wait_p95_seconds);
        $this->assertNotNull($fresh->processing_p95_seconds);
        $this->assertNotNull($fresh->achieved_rps);
        $this->assertNotNull($fresh->achieved_rpm);
        $this->assertSame(3, (int) data_get($fresh->metrics_json, 'aggregation.dispatch_count'));
    }
}
