<?php

namespace App\Models;

class ComfyUiWorkerSession extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'comfyui_worker_sessions';

    protected $fillable = [
        'worker_id',
        'worker_identifier',
        'fleet_slug',
        'stage',
        'instance_type',
        'lifecycle',
        'started_at',
        'ended_at',
        'busy_seconds',
        'running_seconds',
        'utilization',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'busy_seconds' => 'integer',
        'running_seconds' => 'integer',
        'utilization' => 'float',
    ];
}
