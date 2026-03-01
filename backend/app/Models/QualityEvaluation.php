<?php

namespace App\Models;

class QualityEvaluation extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'quality_evaluations';

    protected $fillable = [
        'benchmark_matrix_run_id',
        'benchmark_matrix_run_item_id',
        'effect_test_run_id',
        'benchmark_context_id',
        'rubric_version',
        'provider',
        'model',
        'status',
        'composite_score',
        'vector_json',
        'request_json',
        'result_json',
        'evaluated_at',
    ];

    protected $casts = [
        'benchmark_matrix_run_id' => 'integer',
        'benchmark_matrix_run_item_id' => 'integer',
        'effect_test_run_id' => 'integer',
        'composite_score' => 'float',
        'vector_json' => 'array',
        'request_json' => 'array',
        'result_json' => 'array',
        'evaluated_at' => 'datetime',
    ];
}

