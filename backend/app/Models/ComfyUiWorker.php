<?php

namespace App\Models;

class ComfyUiWorker extends CentralModel
{
    protected $fillable = [
        'worker_id',
        'display_name',
        'environment',
        'capabilities',
        'max_concurrency',
        'current_load',
        'last_seen_at',
        'is_draining',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'max_concurrency' => 'integer',
        'current_load' => 'integer',
        'last_seen_at' => 'datetime',
        'is_draining' => 'boolean',
    ];
}
