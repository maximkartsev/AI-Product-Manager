<?php

namespace App\Services\LoadTest;

use App\Models\LoadTestStage;

class ScenarioScheduler
{
    /**
     * @param array<string, mixed>|LoadTestStage $stage
     */
    public function targetRpsForSecond(array|LoadTestStage $stage, int $elapsedSecond): float
    {
        $duration = max(1, (int) $this->value($stage, 'duration_seconds', 1));
        $second = max(0, min($elapsedSecond, $duration - 1));
        $baseRps = $this->resolveBaseRps($stage);
        $config = $this->config($stage);

        if ($baseRps <= 0) {
            return 0.0;
        }

        return match ((string) $this->value($stage, 'stage_type', 'steady')) {
            'spike' => $this->spikeRps($baseRps, $second, $duration, $config),
            'ramp' => $this->rampRps($baseRps, $second, $duration),
            'drop' => $this->dropRps($baseRps, $second, $duration),
            'sine' => $this->sineRps($baseRps, $second, $duration, $config),
            default => $baseRps,
        };
    }

    /**
     * @param array<string, mixed>|LoadTestStage $stage
     * @return array{count: int, carry: float, target_rps: float}
     */
    public function dispatchCountForSecond(array|LoadTestStage $stage, int $elapsedSecond, float $carry = 0.0): array
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

    /**
     * @param array<string, mixed>|LoadTestStage $stage
     * @return array<int, array{second: int, target_rps: float, count: int, carry: float}>
     */
    public function scheduleForStage(array|LoadTestStage $stage): array
    {
        $duration = max(1, (int) $this->value($stage, 'duration_seconds', 1));
        $carry = 0.0;
        $result = [];

        for ($second = 0; $second < $duration; $second++) {
            $tick = $this->dispatchCountForSecond($stage, $second, $carry);
            $carry = (float) $tick['carry'];
            $result[] = [
                'second' => $second,
                'target_rps' => (float) $tick['target_rps'],
                'count' => (int) $tick['count'],
                'carry' => $carry,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|LoadTestStage $stage
     */
    private function resolveBaseRps(array|LoadTestStage $stage): float
    {
        $targetRps = $this->value($stage, 'target_rps');
        if ($targetRps !== null) {
            return max(0.0, (float) $targetRps);
        }

        $targetRpm = $this->value($stage, 'target_rpm');
        if ($targetRpm !== null) {
            return max(0.0, (float) $targetRpm / 60.0);
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

    /**
     * @param array<string, mixed>|LoadTestStage $stage
     */
    private function config(array|LoadTestStage $stage): array
    {
        $value = $this->value($stage, 'config_json', []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed>|LoadTestStage $stage
     */
    private function value(array|LoadTestStage $stage, string $key, mixed $default = null): mixed
    {
        if (is_array($stage)) {
            return $stage[$key] ?? $default;
        }

        return $stage->{$key} ?? $default;
    }
}
