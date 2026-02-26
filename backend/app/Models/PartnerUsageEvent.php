<?php

namespace App\Models;

class PartnerUsageEvent extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'partner_usage_events';

    protected $fillable = [
        'tenant_id',
        'tenant_job_id',
        'dispatch_id',
        'workflow_id',
        'effect_id',
        'user_id',
        'worker_id',
        'worker_session_id',
        'comfy_prompt_id',
        'node_id',
        'node_class_type',
        'node_display_name',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'credits',
        'cost_usd_reported',
        'usage_json',
        'ui_json',
    ];

    protected $casts = [
        'tenant_job_id' => 'integer',
        'dispatch_id' => 'integer',
        'workflow_id' => 'integer',
        'effect_id' => 'integer',
        'user_id' => 'integer',
        'worker_session_id' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'credits' => 'float',
        'cost_usd_reported' => 'float',
        'usage_json' => 'array',
        'ui_json' => 'array',
    ];
}
