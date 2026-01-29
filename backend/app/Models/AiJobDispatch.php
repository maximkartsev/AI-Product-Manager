<?php

namespace App\Models;

class AiJobDispatch extends CentralModel
{
    protected $fillable = [
        'tenant_id',
        'tenant_job_id',
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
