<?php

namespace App\Services;

class ComfyUiFleetCloudWatchMetricsBuilder
{
    /**
     * @param array{
     *   queueDepth?: int|float,
     *   backlogPerInstance?: int|float,
     *   activeWorkers?: int|float,
     *   availableCapacity?: int|float,
     *   jobProcessingP50?: int|float,
     *   errorRate?: int|float,
     *   leaseExpiredCount?: int|float,
     *   spotInterruptionCount?: int|float
     * } $stats
     * @return array<int, array{
     *   MetricName: string,
     *   Dimensions: array<int, array{Name: string, Value: string}>,
     *   Value: int|float,
     *   Unit: string
     * }>
     */
    public function build(string $fleetSlug, string $stage, array $stats): array
    {
        $dimensions = [
            ['Name' => 'FleetSlug', 'Value' => $fleetSlug],
            ['Name' => 'Stage', 'Value' => $stage],
        ];

        return [
            $this->metric('QueueDepth', $dimensions, (int) ($stats['queueDepth'] ?? 0), 'Count'),
            $this->metric('BacklogPerInstance', $dimensions, (float) ($stats['backlogPerInstance'] ?? 0), 'Count'),
            $this->metric('ActiveWorkers', $dimensions, (int) ($stats['activeWorkers'] ?? 0), 'Count'),
            $this->metric('AvailableCapacity', $dimensions, (int) ($stats['availableCapacity'] ?? 0), 'Count'),
            $this->metric('JobProcessingP50', $dimensions, (float) ($stats['jobProcessingP50'] ?? 0), 'Seconds'),
            $this->metric('ErrorRate', $dimensions, (float) ($stats['errorRate'] ?? 0), 'Percent'),
            $this->metric('LeaseExpiredCount', $dimensions, (int) ($stats['leaseExpiredCount'] ?? 0), 'Count'),
            $this->metric('SpotInterruptionCount', $dimensions, (int) ($stats['spotInterruptionCount'] ?? 0), 'Count'),
        ];
    }

    /**
     * @param array<int, array{Name: string, Value: string}> $dimensions
     * @return array{
     *   MetricName: string,
     *   Dimensions: array<int, array{Name: string, Value: string}>,
     *   Value: int|float,
     *   Unit: string
     * }
     */
    private function metric(string $name, array $dimensions, int|float $value, string $unit): array
    {
        return [
            'MetricName' => $name,
            'Dimensions' => $dimensions,
            'Value' => $value,
            'Unit' => $unit,
        ];
    }
}
