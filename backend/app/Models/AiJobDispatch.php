<?php

namespace App\Models;

class AiJobDispatch extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'tenant_id',
        'tenant_job_id',
        'provider',
        'workflow_id',
        'stage',
        'status',
        'priority',
        'attempts',
        'worker_id',
        'lease_token',
        'lease_expires_at',
        'last_error',
        'duration_seconds',
        'leased_at',
        'last_leased_at',
        'finished_at',
        'processing_seconds',
        'queue_wait_seconds',
        'work_units',
        'work_unit_kind',
    ];

    protected $casts = [
        'workflow_id' => 'integer',
        'priority' => 'integer',
        'attempts' => 'integer',
        'lease_expires_at' => 'datetime',
        'duration_seconds' => 'integer',
        'leased_at' => 'datetime',
        'last_leased_at' => 'datetime',
        'finished_at' => 'datetime',
        'processing_seconds' => 'integer',
        'queue_wait_seconds' => 'integer',
        'work_units' => 'float',
    ];
}
