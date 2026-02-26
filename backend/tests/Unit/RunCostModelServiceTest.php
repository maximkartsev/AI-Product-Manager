<?php

namespace Tests\Unit;

use App\Services\RunCostModelService;
use Tests\TestCase;

class RunCostModelServiceTest extends TestCase
{
    public function test_builds_expected_cost_breakdown_for_multiple_run_counts(): void
    {
        $service = new RunCostModelService();

        $result = $service->build([
            'startup_seconds' => 120,
            'busy_seconds_per_run' => 30,
            'idle_seconds_after_batch' => 60,
            'compute_rate_usd_per_second' => 0.01,
            'partner_cost_usd_per_run' => 0.2,
            'revenue_usd_per_run' => 1.0,
            'run_counts' => [1, 10, 100],
        ]);

        $models = collect($result['models'] ?? [])->keyBy('run_count');
        $this->assertTrue($models->has(1));
        $this->assertTrue($models->has(10));
        $this->assertTrue($models->has(100));

        $oneRun = $models->get(1);
        $tenRun = $models->get(10);

        $this->assertSame(0.3, round((float) ($oneRun['processing_only_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(2.1, round((float) ($oneRun['effective_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(2.3, round((float) ($oneRun['total_cost_usd'] ?? 0), 1));
        $this->assertSame(-1.3, round((float) ($oneRun['margin_usd'] ?? 0), 1));

        $this->assertSame(3.0, round((float) ($tenRun['processing_only_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(4.8, round((float) ($tenRun['effective_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(6.8, round((float) ($tenRun['total_cost_usd'] ?? 0), 1));
        $this->assertSame(3.2, round((float) ($tenRun['margin_usd'] ?? 0), 1));
    }

    public function test_defaults_run_counts_when_invalid_or_empty(): void
    {
        $service = new RunCostModelService();

        $result = $service->build([
            'busy_seconds_per_run' => 1,
            'compute_rate_usd_per_second' => 0.01,
            'run_counts' => [0, -1, 'abc'],
        ]);

        $counts = collect($result['models'] ?? [])->pluck('run_count')->all();
        $this->assertSame([1, 10, 100], $counts);
    }

    public function test_margin_is_null_when_revenue_not_provided(): void
    {
        $service = new RunCostModelService();

        $result = $service->build([
            'busy_seconds_per_run' => 10,
            'compute_rate_usd_per_second' => 0.01,
            'run_counts' => [1],
        ]);

        $model = collect($result['models'] ?? [])->first();
        $this->assertNull($model['revenue_total_usd'] ?? null);
        $this->assertNull($model['margin_usd'] ?? null);
    }
}
