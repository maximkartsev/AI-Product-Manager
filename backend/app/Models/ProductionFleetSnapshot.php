<?php

namespace App\Models;

class ProductionFleetSnapshot extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'production_fleet_snapshots';

    protected $fillable = [
        'execution_environment_id',
        'fleet_slug',
        'stage',
        'captured_at',
        'config_json',
        'composition_json',
        'metrics_json',
        'queue_depth',
        'queue_units',
        'p95_queue_wait_seconds',
        'p95_processing_seconds',
        'interruptions_count',
        'rebalance_recommendations_count',
        'spot_discount_estimate',
    ];

    protected $casts = [
        'execution_environment_id' => 'integer',
        'captured_at' => 'datetime',
        'config_json' => 'array',
        'composition_json' => 'array',
        'metrics_json' => 'array',
        'queue_depth' => 'integer',
        'queue_units' => 'float',
        'p95_queue_wait_seconds' => 'float',
        'p95_processing_seconds' => 'float',
        'interruptions_count' => 'integer',
        'rebalance_recommendations_count' => 'integer',
        'spot_discount_estimate' => 'float',
    ];
}
