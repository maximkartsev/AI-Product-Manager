<?php

namespace App\Models;

class WorkerAuditLog extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    public $timestamps = false;

    protected $fillable = [
        'worker_id',
        'worker_identifier',
        'event',
        'dispatch_id',
        'ip_address',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function worker()
    {
        return $this->belongsTo(ComfyUiWorker::class, 'worker_id');
    }
}
