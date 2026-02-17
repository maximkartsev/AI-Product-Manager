<?php

namespace App\Models;

class ComfyUiWorker extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'comfy_ui_workers';

    protected $fillable = [
        'worker_id',
        'token_hash',
        'display_name',
        'capabilities',
        'max_concurrency',
        'current_load',
        'last_seen_at',
        'is_draining',
        'is_approved',
        'last_ip',
        'registration_source',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'max_concurrency' => 'integer',
        'current_load' => 'integer',
        'last_seen_at' => 'datetime',
        'is_draining' => 'boolean',
        'is_approved' => 'boolean',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function workflows()
    {
        return $this->belongsToMany(Workflow::class, 'worker_workflows', 'worker_id', 'workflow_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(WorkerAuditLog::class, 'worker_id');
    }
}
