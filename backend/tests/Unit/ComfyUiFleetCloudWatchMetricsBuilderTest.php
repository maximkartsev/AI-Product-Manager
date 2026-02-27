<?php

namespace Tests\Unit;

use App\Services\ComfyUiFleetCloudWatchMetricsBuilder;
use Tests\TestCase;

class ComfyUiFleetCloudWatchMetricsBuilderTest extends TestCase
{
    public function test_builds_adr_0005_metric_payload_with_fleet_stage_dimensions_only(): void
    {
        $builder = new ComfyUiFleetCloudWatchMetricsBuilder();

        $metrics = $builder->build('gpu-default', 'staging', [
            'queueDepth' => 12,
            'backlogPerInstance' => 3.5,
            'activeWorkers' => 4,
            'availableCapacity' => 7,
            'jobProcessingP50' => 81.25,
            'errorRate' => 12.5,
            'leaseExpiredCount' => 2,
            'spotInterruptionCount' => 1,
        ]);

        $this->assertCount(8, $metrics);
        $this->assertSame([
            'QueueDepth',
            'BacklogPerInstance',
            'ActiveWorkers',
            'AvailableCapacity',
            'JobProcessingP50',
            'ErrorRate',
            'LeaseExpiredCount',
            'SpotInterruptionCount',
        ], array_column($metrics, 'MetricName'));

        foreach ($metrics as $metric) {
            $dimensions = collect($metric['Dimensions'] ?? [])->keyBy('Name');

            $this->assertSame(['FleetSlug', 'Stage'], $dimensions->keys()->all());
            $this->assertSame('gpu-default', $dimensions->get('FleetSlug')['Value'] ?? null);
            $this->assertSame('staging', $dimensions->get('Stage')['Value'] ?? null);
        }
    }

    public function test_builds_expected_units_for_each_metric(): void
    {
        $builder = new ComfyUiFleetCloudWatchMetricsBuilder();

        $metrics = $builder->build('gpu-default', 'production', []);

        $unitsByMetric = collect($metrics)
            ->mapWithKeys(fn (array $metric) => [$metric['MetricName'] => $metric['Unit']])
            ->all();

        $this->assertSame([
            'QueueDepth' => 'Count',
            'BacklogPerInstance' => 'Count',
            'ActiveWorkers' => 'Count',
            'AvailableCapacity' => 'Count',
            'JobProcessingP50' => 'Seconds',
            'ErrorRate' => 'Percent',
            'LeaseExpiredCount' => 'Count',
            'SpotInterruptionCount' => 'Count',
        ], $unitsByMetric);
    }
}
