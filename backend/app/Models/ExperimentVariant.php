<?php

namespace App\Models;

class ExperimentVariant extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'experiment_variants';

    protected $fillable = [
        'name',
        'description',
        'target_environment_kind',
        'fleet_config_intent_json',
        'constraints_json',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'fleet_config_intent_json' => 'array',
        'constraints_json' => 'array',
        'is_active' => 'boolean',
        'created_by_user_id' => 'integer',
    ];
}
