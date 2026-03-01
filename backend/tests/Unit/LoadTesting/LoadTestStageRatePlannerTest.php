<?php

namespace Tests\Unit\LoadTesting;

use App\Models\LoadTestStage;
use App\Services\LoadTesting\LoadTestStageRatePlanner;
use Tests\TestCase;

class LoadTestStageRatePlannerTest extends TestCase
{
    public function test_steady_stage_keeps_constant_rps(): void
    {
        $planner = new LoadTestStageRatePlanner();
        $stage = new LoadTestStage([
            'stage_type' => 'steady',
            'duration_seconds' => 10,
            'target_rps' => 4.5,
        ]);

        $this->assertSame(4.5, $planner->targetRpsForSecond($stage, 0));
        $this->assertSame(4.5, $planner->targetRpsForSecond($stage, 9));
    }

    public function test_ramp_stage_scales_from_zero_to_target(): void
    {
        $planner = new LoadTestStageRatePlanner();
        $stage = new LoadTestStage([
            'stage_type' => 'ramp',
            'duration_seconds' => 6,
            'target_rps' => 6.0,
        ]);

        $this->assertSame(0.0, $planner->targetRpsForSecond($stage, 0));
        $this->assertGreaterThan(2.0, $planner->targetRpsForSecond($stage, 3));
        $this->assertSame(6.0, $planner->targetRpsForSecond($stage, 5));
    }

    public function test_drop_stage_scales_down_to_zero(): void
    {
        $planner = new LoadTestStageRatePlanner();
        $stage = new LoadTestStage([
            'stage_type' => 'drop',
            'duration_seconds' => 5,
            'target_rps' => 10.0,
        ]);

        $this->assertSame(10.0, $planner->targetRpsForSecond($stage, 0));
        $this->assertSame(0.0, $planner->targetRpsForSecond($stage, 4));
    }

    public function test_dispatch_count_includes_fractional_carry(): void
    {
        $planner = new LoadTestStageRatePlanner();
        $stage = new LoadTestStage([
            'stage_type' => 'steady',
            'duration_seconds' => 1,
            'target_rps' => 1.25,
        ]);

        $tick1 = $planner->dispatchCountForSecond($stage, 0, 0.0);
        $this->assertSame(1, $tick1['count']);
        $this->assertEqualsWithDelta(0.25, $tick1['carry'], 0.0001);

        $tick2 = $planner->dispatchCountForSecond($stage, 0, (float) $tick1['carry']);
        $this->assertSame(1, $tick2['count']);
        $this->assertEqualsWithDelta(0.5, $tick2['carry'], 0.0001);
    }
}

