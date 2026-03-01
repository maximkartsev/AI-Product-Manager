<?php

namespace App\Models;

class ActionLog extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'action_logs';

    protected $fillable = [
        'event',
        'severity',
        'module',
        'telemetry_sink',
        'message',
        'economic_impact_json',
        'operator_action_json',
        'context_json',
        'occurred_at',
        'resolved_at',
    ];

    protected $casts = [
        'economic_impact_json' => 'array',
        'operator_action_json' => 'array',
        'context_json' => 'array',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}

