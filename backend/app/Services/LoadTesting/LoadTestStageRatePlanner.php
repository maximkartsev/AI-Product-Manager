<?php

namespace App\Services\LoadTesting;

use App\Models\LoadTestStage;

class LoadTestStageRatePlanner
{
    public function targetRpsForSecond(LoadTestStage $stage, int $elapsedSecond): float
    {
        $duration = max(1, (int) $stage->duration_seconds);
        $second = max(0, min($elapsedSecond, $duration - 1));
        $baseRps = $this->resolveBaseRps($stage);
        $config = is_array($stage->config_json) ? $stage->config_json : [];

        if ($baseRps <= 0) {
            return 0.0;
        }

        return match ($stage->stage_type) {
            'spike' => $this->spikeRps($baseRps, $second, $duration, $config),
            'ramp' => $this->rampRps($baseRps, $second, $duration),
            'drop' => $this->dropRps($baseRps, $second, $duration),
            'sine' => $this->sineRps($baseRps, $second, $duration, $config),
            default => $baseRps,
        };
    }

    public function dispatchCountForSecond(LoadTestStage $stage, int $elapsedSecond, float $carry = 0.0): array
    {
        $rps = $this->targetRpsForSecond($stage, $elapsedSecond);
        $raw = max(0.0, $rps + $carry);
        $count = (int) floor($raw);

        return [
            'count' => $count,
            'carry' => $raw - $count,
            'target_rps' => $rps,
        ];
    }

    private function resolveBaseRps(LoadTestStage $stage): float
    {
        if ($stage->target_rps !== null) {
            return max(0.0, (float) $stage->target_rps);
        }

        if ($stage->target_rpm !== null) {
            return max(0.0, (float) $stage->target_rpm / 60.0);
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function spikeRps(float $baseRps, int $second, int $duration, array $config): float
    {
        $multiplier = (float) ($config['spike_multiplier'] ?? 2.0);
        $spikeSeconds = max(1, (int) ($config['spike_seconds'] ?? min(3, $duration)));

        if ($second < $spikeSeconds) {
            return max(0.0, $baseRps * max(1.0, $multiplier));
        }

        return $baseRps;
    }

    private function rampRps(float $baseRps, int $second, int $duration): float
    {
        $progress = $duration <= 1 ? 1.0 : ($second / ($duration - 1));

        return max(0.0, $baseRps * $progress);
    }

    private function dropRps(float $baseRps, int $second, int $duration): float
    {
        $progress = $duration <= 1 ? 1.0 : ($second / ($duration - 1));

        return max(0.0, $baseRps * (1.0 - $progress));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function sineRps(float $baseRps, int $second, int $duration, array $config): float
    {
        $cycles = max(1.0, (float) ($config['sine_cycles'] ?? 1.0));
        $phaseOffset = (float) ($config['sine_phase_offset'] ?? 0.0);
        $progress = $duration <= 1 ? 1.0 : ($second / ($duration - 1));
        $value = sin(($progress * $cycles * 2 * M_PI) + $phaseOffset);

        return max(0.0, $baseRps * (0.5 + (0.5 * $value)));
    }
}

