<?php

namespace App\Models;

class DevNode extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'dev_nodes';

    protected $fillable = [
        'name',
        'instance_type',
        'stage',
        'lifecycle',
        'status',
        'aws_instance_id',
        'public_endpoint',
        'private_endpoint',
        'active_bundle_ref',
        'assigned_to_user_id',
        'started_at',
        'ready_at',
        'ended_at',
        'last_activity_at',
        'metadata_json',
    ];

    protected $casts = [
        'assigned_to_user_id' => 'integer',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function executionEnvironment()
    {
        return $this->hasOne(ExecutionEnvironment::class, 'dev_node_id');
    }
}
