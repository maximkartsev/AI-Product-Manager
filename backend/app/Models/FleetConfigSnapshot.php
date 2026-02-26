<?php

namespace App\Models;

class FleetConfigSnapshot extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'fleet_config_snapshots';

    protected $fillable = [
        'execution_environment_id',
        'experiment_variant_id',
        'snapshot_scope',
        'config_json',
        'composition_json',
        'captured_at',
    ];

    protected $casts = [
        'execution_environment_id' => 'integer',
        'experiment_variant_id' => 'integer',
        'config_json' => 'array',
        'composition_json' => 'array',
        'captured_at' => 'datetime',
    ];

    public function executionEnvironment()
    {
        return $this->belongsTo(ExecutionEnvironment::class, 'execution_environment_id');
    }

    public function experimentVariant()
    {
        return $this->belongsTo(ExperimentVariant::class, 'experiment_variant_id');
    }
}
