<?php

namespace App\Models;

class ExecutionEnvironment extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'execution_environments';

    protected $fillable = [
        'name',
        'kind',
        'stage',
        'fleet_slug',
        'dev_node_id',
        'configuration_json',
        'is_active',
    ];

    protected $casts = [
        'dev_node_id' => 'integer',
        'configuration_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function devNode()
    {
        return $this->belongsTo(DevNode::class, 'dev_node_id');
    }
}
