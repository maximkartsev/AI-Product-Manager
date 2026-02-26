<?php

namespace App\Models;

class EffectTestRun extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'effect_test_runs';

    protected $fillable = [
        'effect_id',
        'effect_revision_id',
        'workflow_revision_id',
        'execution_environment_id',
        'test_input_set_id',
        'run_mode',
        'target_count',
        'overrides_json',
        'status',
        'started_at',
        'completed_at',
        'p50_latency_ms',
        'p95_latency_ms',
        'p99_latency_ms',
        'error_rate_percent',
        'compute_cost_usd',
        'effective_cost_usd',
        'partner_cost_usd',
        'margin_usd',
        'metrics_json',
        'created_by_user_id',
    ];

    protected $casts = [
        'effect_id' => 'integer',
        'effect_revision_id' => 'integer',
        'workflow_revision_id' => 'integer',
        'execution_environment_id' => 'integer',
        'test_input_set_id' => 'integer',
        'target_count' => 'integer',
        'overrides_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'p50_latency_ms' => 'float',
        'p95_latency_ms' => 'float',
        'p99_latency_ms' => 'float',
        'error_rate_percent' => 'float',
        'compute_cost_usd' => 'float',
        'effective_cost_usd' => 'float',
        'partner_cost_usd' => 'float',
        'margin_usd' => 'float',
        'metrics_json' => 'array',
        'created_by_user_id' => 'integer',
    ];
}
