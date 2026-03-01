<?php

namespace Tests\Unit;

use App\Models\LoadTestStage;
use App\Services\LoadTest\ScenarioScheduler;
use Tests\TestCase;

class ScenarioSchedulerTest extends TestCase
{
    public function test_steady_stage_keeps_constant_rps(): void
    {
        $scheduler = new ScenarioScheduler();
        $stage = new LoadTestStage([
            'stage_type' => 'steady',
            'duration_seconds' => 10,
            'target_rps' => 4.5,
        ]);

        $this->assertSame(4.5, $scheduler->targetRpsForSecond($stage, 0));
        $this->assertSame(4.5, $scheduler->targetRpsForSecond($stage, 9));
    }

    public function test_spike_stage_boosts_early_seconds_then_returns_to_base_rate(): void
    {
        $scheduler = new ScenarioScheduler();
        $stage = new LoadTestStage([
            'stage_type' => 'spike',
            'duration_seconds' => 6,
            'target_rps' => 2.0,
            'config_json' => [
                'spike_multiplier' => 3,
                'spike_seconds' => 2,
            ],
        ]);

        $this->assertSame(6.0, $scheduler->targetRpsForSecond($stage, 0));
        $this->assertSame(6.0, $scheduler->targetRpsForSecond($stage, 1));
        $this->assertSame(2.0, $scheduler->targetRpsForSecond($stage, 3));
    }

    public function test_ramp_drop_and_sine_shapes_are_deterministic(): void
    {
        $scheduler = new ScenarioScheduler();

        $ramp = new LoadTestStage([
            'stage_type' => 'ramp',
            'duration_seconds' => 5,
            'target_rps' => 10.0,
        ]);
        $this->assertSame(0.0, $scheduler->targetRpsForSecond($ramp, 0));
        $this->assertSame(10.0, $scheduler->targetRpsForSecond($ramp, 4));

        $drop = new LoadTestStage([
            'stage_type' => 'drop',
            'duration_seconds' => 5,
            'target_rps' => 10.0,
        ]);
        $this->assertSame(10.0, $scheduler->targetRpsForSecond($drop, 0));
        $this->assertSame(0.0, $scheduler->targetRpsForSecond($drop, 4));

        $sine = new LoadTestStage([
            'stage_type' => 'sine',
            'duration_seconds' => 5,
            'target_rps' => 10.0,
            'config_json' => ['sine_cycles' => 1.0],
        ]);
        $first = $scheduler->targetRpsForSecond($sine, 0);
        $mid = $scheduler->targetRpsForSecond($sine, 2);
        $last = $scheduler->targetRpsForSecond($sine, 4);

        $this->assertGreaterThanOrEqual(0.0, $first);
        $this->assertGreaterThanOrEqual(0.0, $mid);
        $this->assertGreaterThanOrEqual(0.0, $last);
    }

    public function test_dispatch_count_tracks_fractional_carry_between_ticks(): void
    {
        $scheduler = new ScenarioScheduler();
        $stage = new LoadTestStage([
            'stage_type' => 'steady',
            'duration_seconds' => 1,
            'target_rps' => 1.25,
        ]);

        $tick1 = $scheduler->dispatchCountForSecond($stage, 0, 0.0);
        $this->assertSame(1, $tick1['count']);
        $this->assertEqualsWithDelta(0.25, $tick1['carry'], 0.0001);

        $tick2 = $scheduler->dispatchCountForSecond($stage, 0, (float) $tick1['carry']);
        $this->assertSame(1, $tick2['count']);
        $this->assertEqualsWithDelta(0.5, $tick2['carry'], 0.0001);
    }
}
