<?php

namespace App\Services\LoadTesting;

use App\Models\AiJobDispatch;
use App\Models\LoadTestRun;
use Carbon\CarbonInterface;

class LoadTestMetricsAggregator
{
    /**
     * @param array<int, array<string, mixed>> $dispatches
     * @return array<string, mixed>
     */
    public function aggregateFromArray(
        array $dispatches,
        ?CarbonInterface $startedAt = null,
        ?CarbonInterface $completedAt = null
    ): array {
        $successCount = 0;
        $failureCount = 0;
        $latenciesMs = [];
        $queueWaitSeconds = [];
        $processingSeconds = [];

        foreach ($dispatches as $dispatch) {
            $status = (string) ($dispatch['status'] ?? '');
            if ($status === 'completed') {
                $successCount++;
            } elseif ($status === 'failed') {
                $failureCount++;
            }

            $durationSeconds = $this->normalizeFloat($dispatch['duration_seconds'] ?? null);
            if ($durationSeconds !== null) {
                $latenciesMs[] = $durationSeconds * 1000.0;
            }

            $queueWait = $this->normalizeFloat($dispatch['queue_wait_seconds'] ?? null);
            if ($queueWait !== null) {
                $queueWaitSeconds[] = $queueWait;
            }

            $processing = $this->normalizeFloat($dispatch['processing_seconds'] ?? null);
            if ($processing !== null) {
                $processingSeconds[] = $processing;
            }
        }

        $totalCount = $successCount + $failureCount;
        $errorRate = $totalCount > 0
            ? round(($failureCount / $totalCount) * 100.0, 4)
            : 0.0;

        $elapsedSeconds = null;
        if ($startedAt && $completedAt) {
            $elapsedSeconds = max(1, abs($completedAt->diffInSeconds($startedAt)));
        }
        $achievedRps = ($elapsedSeconds && $totalCount > 0)
            ? round($totalCount / $elapsedSeconds, 4)
            : null;

        return [
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'dispatch_count' => count($dispatches),
            'error_rate_percent' => $errorRate,
            'p95_latency_ms' => $this->percentile($latenciesMs, 0.95),
            'queue_wait_p95_seconds' => $this->percentile($queueWaitSeconds, 0.95),
            'processing_p95_seconds' => $this->percentile($processingSeconds, 0.95),
            'achieved_rps' => $achievedRps,
            'achieved_rpm' => $achievedRps !== null ? round($achievedRps * 60.0, 4) : null,
        ];
    }

    public function aggregateAndPersist(LoadTestRun $run): LoadTestRun
    {
        $dispatches = AiJobDispatch::query()
            ->where('load_test_run_id', $run->id)
            ->get([
                'status',
                'duration_seconds',
                'queue_wait_seconds',
                'processing_seconds',
            ])
            ->map(fn (AiJobDispatch $dispatch) => [
                'status' => $dispatch->status,
                'duration_seconds' => $dispatch->duration_seconds,
                'queue_wait_seconds' => $dispatch->queue_wait_seconds,
                'processing_seconds' => $dispatch->processing_seconds,
            ])
            ->values()
            ->all();

        $summary = $this->aggregateFromArray(
            $dispatches,
            $run->started_at,
            $run->completed_at
        );

        $run->success_count = (int) ($summary['success_count'] ?? 0);
        $run->failure_count = (int) ($summary['failure_count'] ?? 0);
        $run->p95_latency_ms = $summary['p95_latency_ms'];
        $run->queue_wait_p95_seconds = $summary['queue_wait_p95_seconds'];
        $run->processing_p95_seconds = $summary['processing_p95_seconds'];
        $run->achieved_rps = $summary['achieved_rps'];
        $run->achieved_rpm = $summary['achieved_rpm'];
        $run->metrics_json = array_merge($run->metrics_json ?? [], [
            'aggregation' => $summary,
        ]);
        $run->save();

        return $run->fresh();
    }

    /**
     * @param array<int, float|int> $values
     */
    private function percentile(array $values, float $percentile): ?float
    {
        if (empty($values)) {
            return null;
        }

        sort($values);
        $index = (int) floor((count($values) - 1) * max(0.0, min(1.0, $percentile)));
        $value = $values[$index] ?? null;
        if ($value === null) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}

