<?php

namespace Tests\Unit\LoadTesting;

use App\Services\LoadTesting\LoadTestMetricsAggregator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LoadTestMetricsAggregatorTest extends TestCase
{
    public function test_it_aggregates_latency_and_counts(): void
    {
        $aggregator = new LoadTestMetricsAggregator();
        $started = CarbonImmutable::now()->startOfSecond();
        $completed = $started->addSeconds(10);

        $summary = $aggregator->aggregateFromArray([
            [
                'status' => 'completed',
                'duration_seconds' => 2.1,
                'queue_wait_seconds' => 0.8,
                'processing_seconds' => 1.3,
            ],
            [
                'status' => 'completed',
                'duration_seconds' => 1.5,
                'queue_wait_seconds' => 0.4,
                'processing_seconds' => 1.1,
            ],
            [
                'status' => 'failed',
                'duration_seconds' => 4.5,
                'queue_wait_seconds' => 2.0,
                'processing_seconds' => 2.5,
            ],
        ], $started, $completed);

        $this->assertSame(2, $summary['success_count']);
        $this->assertSame(1, $summary['failure_count']);
        $this->assertSame(3, $summary['dispatch_count']);
        $this->assertEqualsWithDelta(33.3333, (float) $summary['error_rate_percent'], 0.0001);
        $this->assertEqualsWithDelta(0.3, (float) $summary['achieved_rps'], 0.0001);
        $this->assertEqualsWithDelta(18.0, (float) $summary['achieved_rpm'], 0.0001);
        $this->assertNotNull($summary['p95_latency_ms']);
        $this->assertNotNull($summary['queue_wait_p95_seconds']);
        $this->assertNotNull($summary['processing_p95_seconds']);
    }

    public function test_it_handles_empty_dispatches(): void
    {
        $aggregator = new LoadTestMetricsAggregator();
        $summary = $aggregator->aggregateFromArray([]);

        $this->assertSame(0, $summary['success_count']);
        $this->assertSame(0, $summary['failure_count']);
        $this->assertSame(0, $summary['dispatch_count']);
        $this->assertSame(0.0, $summary['error_rate_percent']);
        $this->assertNull($summary['achieved_rps']);
    }
}

