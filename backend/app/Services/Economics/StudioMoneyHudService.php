<?php

namespace App\Services\Economics;

use App\Models\AiJobDispatch;
use App\Models\BenchmarkMatrixRun;
use App\Models\EconomicsSetting;
use App\Models\ExecutionEnvironment;
use App\Models\PartnerUsageEvent;
use App\Models\QualityEvaluation;

class StudioMoneyHudService
{
    /**
     * @return array<string, mixed>
     */
    public function buildForBenchmarkRun(int $benchmarkMatrixRunId): array
    {
        $run = BenchmarkMatrixRun::query()->with('items')->find($benchmarkMatrixRunId);
        if (!$run) {
            throw new \RuntimeException('Benchmark matrix run not found.');
        }

        $settings = EconomicsSetting::query()->first();
        $tokenUsdRate = (float) ($settings?->token_usd_rate ?? 0.01);
        $spotMultiplier = (float) ($settings?->spot_multiplier ?? 1.0);
        $instanceRates = is_array($settings?->instance_type_rates) ? $settings->instance_type_rates : [];

        $rows = [];
        foreach ($run->items as $item) {
            $dispatchIds = collect((array) data_get($item->metrics_json, 'dispatch_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->values()
                ->all();

            $dispatches = empty($dispatchIds)
                ? collect()
                : AiJobDispatch::query()
                    ->whereIn('id', $dispatchIds)
                    ->get([
                        'status',
                        'duration_seconds',
                        'processing_seconds',
                        'queue_wait_seconds',
                    ]);

            $successCount = $dispatches->where('status', 'completed')->count();
            $failureCount = $dispatches->where('status', 'failed')->count();
            $dispatchCount = $dispatches->count();
            $processingSeconds = (float) $dispatches
                ->sum(fn ($dispatch) => (float) ($dispatch->processing_seconds ?? $dispatch->duration_seconds ?? 0.0));
            $queueWaitValues = $dispatches->pluck('queue_wait_seconds')->filter()->map(fn ($value) => (float) $value)->values()->all();
            $latencyValues = $dispatches->pluck('duration_seconds')->filter()->map(fn ($value) => (float) $value)->values()->all();

            $environment = ExecutionEnvironment::query()->find((int) ($item->execution_environment_id ?? 0));
            $instanceType = (string) data_get($environment?->configuration_json, 'instance_type', '');
            $hourlyRate = (float) ($instanceRates[$instanceType] ?? 0.0);
            $effectiveRatePerSecond = ($hourlyRate / 3600.0) * max(0.0, $spotMultiplier ?: 1.0);

            $computeCostUsd = round($processingSeconds * $effectiveRatePerSecond, 6);
            $partnerCostUsd = empty($dispatchIds)
                ? 0.0
                : (float) PartnerUsageEvent::query()
                    ->whereIn('dispatch_id', $dispatchIds)
                    ->sum('cost_usd_reported');
            $partnerCostUsd = round($partnerCostUsd, 6);

            $estimatedRevenueUsd = round($dispatchCount * $tokenUsdRate, 6);
            $marginUsd = round($estimatedRevenueUsd - $computeCostUsd - $partnerCostUsd, 6);
            $qualityScore = (float) (QualityEvaluation::query()
                ->where('benchmark_matrix_run_item_id', $item->id)
                ->latest('id')
                ->value('composite_score') ?? 0.0);
            $failureRate = $dispatchCount > 0 ? round(($failureCount / $dispatchCount) * 100.0, 4) : 0.0;

            $row = [
                'benchmark_matrix_run_item_id' => $item->id,
                'variant_id' => $item->variant_id,
                'execution_environment_id' => $item->execution_environment_id,
                'instance_type' => $instanceType !== '' ? $instanceType : null,
                'dispatch_count' => $dispatchCount,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'failure_rate_percent' => $failureRate,
                'quality_score' => round($qualityScore, 4),
                'p95_latency_seconds' => $this->percentile($latencyValues, 0.95),
                'queue_wait_p95_seconds' => $this->percentile($queueWaitValues, 0.95),
                'processing_seconds_total' => round($processingSeconds, 4),
                'compute_cost_usd' => $computeCostUsd,
                'partner_cost_usd' => $partnerCostUsd,
                'estimated_revenue_usd' => $estimatedRevenueUsd,
                'margin_usd' => $marginUsd,
                'bottleneck_classification' => $this->classifyBottleneck(
                    $failureRate,
                    $this->percentile($queueWaitValues, 0.95),
                    $this->percentile($latencyValues, 0.95)
                ),
            ];
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => $b['margin_usd'] <=> $a['margin_usd']);
        $winner = $rows[0] ?? null;

        return [
            'benchmark_matrix_run_id' => $run->id,
            'benchmark_context_id' => $run->benchmark_context_id,
            'status' => $run->status,
            'rows' => $rows,
            'winner' => $winner,
            'totals' => [
                'compute_cost_usd' => round(array_sum(array_column($rows, 'compute_cost_usd')), 6),
                'partner_cost_usd' => round(array_sum(array_column($rows, 'partner_cost_usd')), 6),
                'estimated_revenue_usd' => round(array_sum(array_column($rows, 'estimated_revenue_usd')), 6),
                'margin_usd' => round(array_sum(array_column($rows, 'margin_usd')), 6),
            ],
            'recommendations' => $this->buildRecommendations($rows, $winner),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null $winner
     * @return array<int, array<string, mixed>>
     */
    private function buildRecommendations(array $rows, ?array $winner): array
    {
        $recommendations = [];
        if ($winner) {
            $recommendations[] = [
                'type' => 'routing',
                'action' => 'apply_variant',
                'target_variant_id' => $winner['variant_id'],
                'reason' => 'Highest measured margin in benchmark matrix.',
                'expected_margin_usd' => $winner['margin_usd'],
            ];
        }

        foreach ($rows as $row) {
            if (($row['failure_rate_percent'] ?? 0) >= 5) {
                $recommendations[] = [
                    'type' => 'reliability',
                    'action' => 'investigate_variant_path',
                    'target_variant_id' => $row['variant_id'],
                    'reason' => 'Failure rate is above 5%.',
                    'failure_rate_percent' => $row['failure_rate_percent'],
                ];
            }
            if (($row['queue_wait_p95_seconds'] ?? 0) >= 20) {
                $recommendations[] = [
                    'type' => 'capacity',
                    'action' => 'increase_capacity_or_shift_variant',
                    'target_variant_id' => $row['variant_id'],
                    'reason' => 'Queue wait p95 indicates fleet saturation.',
                    'queue_wait_p95_seconds' => $row['queue_wait_p95_seconds'],
                ];
            }
        }

        return $recommendations;
    }

    private function classifyBottleneck(float $failureRatePercent, ?float $queueWaitP95, ?float $latencyP95): string
    {
        if ($failureRatePercent >= 5.0) {
            return 'fault';
        }
        if (($queueWaitP95 ?? 0.0) >= 20.0) {
            return 'queue';
        }
        if (($latencyP95 ?? 0.0) >= 60.0) {
            return 'gpu';
        }

        return 'none';
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

        return round((float) ($values[$index] ?? 0.0), 4);
    }
}

