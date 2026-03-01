<?php

namespace App\Models;

class LoadTestRun extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'load_test_runs';

    protected $fillable = [
        'load_test_scenario_id',
        'execution_environment_id',
        'effect_revision_id',
        'experiment_variant_id',
        'fleet_config_snapshot_start_id',
        'fleet_config_snapshot_end_id',
        'status',
        'achieved_rpm',
        'achieved_rps',
        'success_count',
        'failure_count',
        'p95_latency_ms',
        'queue_wait_p95_seconds',
        'processing_p95_seconds',
        'compute_cost_usd',
        'effective_cost_usd',
        'partner_cost_usd',
        'margin_usd',
        'metrics_json',
        'started_at',
        'completed_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'load_test_scenario_id' => 'integer',
        'execution_environment_id' => 'integer',
        'effect_revision_id' => 'integer',
        'experiment_variant_id' => 'integer',
        'fleet_config_snapshot_start_id' => 'integer',
        'fleet_config_snapshot_end_id' => 'integer',
        'achieved_rpm' => 'float',
        'achieved_rps' => 'float',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'p95_latency_ms' => 'float',
        'queue_wait_p95_seconds' => 'float',
        'processing_p95_seconds' => 'float',
        'compute_cost_usd' => 'float',
        'effective_cost_usd' => 'float',
        'partner_cost_usd' => 'float',
        'margin_usd' => 'float',
        'metrics_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_by_user_id' => 'integer',
    ];

    public function scenario()
    {
        return $this->belongsTo(LoadTestScenario::class, 'load_test_scenario_id');
    }

    public function executionEnvironment()
    {
        return $this->belongsTo(ExecutionEnvironment::class, 'execution_environment_id');
    }
}
