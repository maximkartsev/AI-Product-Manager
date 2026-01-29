<?php

namespace App\Models;

class AiJobDispatch extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'tenant_id',
        'tenant_job_id',
        'provider',
        'status',
        'priority',
        'attempts',
        'worker_id',
        'lease_token',
        'lease_expires_at',
        'last_error',
    ];

    protected $casts = [
        'priority' => 'integer',
        'attempts' => 'integer',
        'lease_expires_at' => 'datetime',
    ];
}
