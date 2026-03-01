<?php

namespace App\Models;

class BenchmarkMatrixRunItem extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'benchmark_matrix_run_items';

    protected $fillable = [
        'benchmark_matrix_run_id',
        'variant_id',
        'execution_environment_id',
        'experiment_variant_id',
        'effect_test_run_id',
        'dispatch_count',
        'status',
        'metrics_json',
    ];

    protected $casts = [
        'benchmark_matrix_run_id' => 'integer',
        'execution_environment_id' => 'integer',
        'experiment_variant_id' => 'integer',
        'effect_test_run_id' => 'integer',
        'dispatch_count' => 'integer',
        'metrics_json' => 'array',
    ];

    public function run()
    {
        return $this->belongsTo(BenchmarkMatrixRun::class, 'benchmark_matrix_run_id');
    }
}

