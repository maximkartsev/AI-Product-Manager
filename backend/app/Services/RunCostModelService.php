<?php

namespace App\Services;

class RunCostModelService
{
    /**
     * @param array{
     *   startup_seconds?: float|int|string|null,
     *   busy_seconds_per_run: float|int|string,
     *   idle_seconds_after_batch?: float|int|string|null,
     *   compute_rate_usd_per_second: float|int|string,
     *   partner_cost_usd_per_run?: float|int|string|null,
     *   revenue_usd_per_run?: float|int|string|null,
     *   run_counts?: array<int, int|float|string>
     * } $input
     * @return array{
     *   assumptions: array<string, mixed>,
     *   models: array<int, array<string, mixed>>
     * }
     */
    public function build(array $input): array
    {
        $startupSeconds = $this->normalizeNonNegativeNumber($input['startup_seconds'] ?? 0);
        $busySecondsPerRun = $this->normalizeNonNegativeNumber($input['busy_seconds_per_run'] ?? 0);
        $idleSecondsAfterBatch = $this->normalizeNonNegativeNumber($input['idle_seconds_after_batch'] ?? 0);
        $computeRateUsdPerSecond = $this->normalizeNonNegativeNumber($input['compute_rate_usd_per_second'] ?? 0);
        $partnerCostUsdPerRun = $this->normalizeNonNegativeNumber($input['partner_cost_usd_per_run'] ?? 0);
        $revenueUsdPerRun = array_key_exists('revenue_usd_per_run', $input)
            ? $this->normalizeNonNegativeNumber($input['revenue_usd_per_run'])
            : null;

        $runCounts = $this->normalizeRunCounts($input['run_counts'] ?? [1, 10, 100]);
        $models = [];

        foreach ($runCounts as $runCount) {
            $processingSecondsTotal = $busySecondsPerRun * $runCount;
            $effectiveSecondsTotal = $startupSeconds + $processingSecondsTotal + $idleSecondsAfterBatch;

            $processingOnlyCostUsd = round($processingSecondsTotal * $computeRateUsdPerSecond, 8);
            $effectiveComputeCostUsd = round($effectiveSecondsTotal * $computeRateUsdPerSecond, 8);
            $partnerCostUsd = round($partnerCostUsdPerRun * $runCount, 8);
            $totalCostUsd = round($effectiveComputeCostUsd + $partnerCostUsd, 8);

            $revenueTotalUsd = $revenueUsdPerRun !== null
                ? round($revenueUsdPerRun * $runCount, 8)
                : null;
            $marginUsd = $revenueTotalUsd !== null
                ? round($revenueTotalUsd - $totalCostUsd, 8)
                : null;

            $models[] = [
                'run_count' => $runCount,
                'processing_seconds_total' => round($processingSecondsTotal, 4),
                'effective_seconds_total' => round($effectiveSecondsTotal, 4),
                'processing_only_compute_cost_usd' => $processingOnlyCostUsd,
                'effective_compute_cost_usd' => $effectiveComputeCostUsd,
                'partner_cost_usd' => $partnerCostUsd,
                'total_cost_usd' => $totalCostUsd,
                'effective_cost_per_run_usd' => $runCount > 0 ? round($totalCostUsd / $runCount, 8) : 0.0,
                'revenue_total_usd' => $revenueTotalUsd,
                'margin_usd' => $marginUsd,
            ];
        }

        return [
            'assumptions' => [
                'startup_seconds' => $startupSeconds,
                'busy_seconds_per_run' => $busySecondsPerRun,
                'idle_seconds_after_batch' => $idleSecondsAfterBatch,
                'compute_rate_usd_per_second' => $computeRateUsdPerSecond,
                'partner_cost_usd_per_run' => $partnerCostUsdPerRun,
                'revenue_usd_per_run' => $revenueUsdPerRun,
            ],
            'models' => $models,
        ];
    }

    /**
     * @param array<int, int|float|string> $values
     * @return array<int, int>
     */
    private function normalizeRunCounts(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (is_int($value)) {
                $candidate = $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $candidate = (int) $value;
            } elseif (is_numeric($value)) {
                $candidate = (int) $value;
            } else {
                continue;
            }

            if ($candidate <= 0) {
                continue;
            }

            $normalized[] = $candidate;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return !empty($normalized) ? $normalized : [1, 10, 100];
    }

    private function normalizeNonNegativeNumber(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $number = (float) $value;
        return $number >= 0 ? $number : 0.0;
    }
}
