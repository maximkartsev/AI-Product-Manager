<?php

namespace App\Models;

class BenchmarkMatrixRun extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'benchmark_matrix_runs';

    protected $fillable = [
        'benchmark_context_id',
        'effect_revision_id',
        'stage',
        'status',
        'runs_per_variant',
        'variant_count',
        'metrics_json',
        'created_by_user_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'effect_revision_id' => 'integer',
        'runs_per_variant' => 'integer',
        'variant_count' => 'integer',
        'metrics_json' => 'array',
        'created_by_user_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(BenchmarkMatrixRunItem::class, 'benchmark_matrix_run_id');
    }
}

