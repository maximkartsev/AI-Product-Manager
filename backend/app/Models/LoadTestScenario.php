<?php

namespace App\Models;

class LoadTestScenario extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'load_test_scenarios';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    public function stages()
    {
        return $this->hasMany(LoadTestStage::class, 'load_test_scenario_id')->orderBy('stage_order');
    }
}
