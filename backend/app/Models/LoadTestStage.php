<?php

namespace App\Models;

class LoadTestStage extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'load_test_stages';

    protected $fillable = [
        'load_test_scenario_id',
        'stage_order',
        'stage_type',
        'duration_seconds',
        'target_rpm',
        'target_rps',
        'fault_enabled',
        'fault_kind',
        'fault_interruption_rate',
        'fault_target_scope',
        'fault_method',
        'fault_notice_seconds',
        'economics_spot_discount_override',
        'config_json',
    ];

    protected $casts = [
        'load_test_scenario_id' => 'integer',
        'stage_order' => 'integer',
        'duration_seconds' => 'integer',
        'target_rpm' => 'float',
        'target_rps' => 'float',
        'fault_enabled' => 'boolean',
        'fault_interruption_rate' => 'float',
        'fault_notice_seconds' => 'integer',
        'economics_spot_discount_override' => 'float',
        'config_json' => 'array',
    ];

    public function scenario()
    {
        return $this->belongsTo(LoadTestScenario::class, 'load_test_scenario_id');
    }
}
