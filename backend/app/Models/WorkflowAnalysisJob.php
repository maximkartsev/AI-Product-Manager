<?php

namespace App\Models;

class WorkflowAnalysisJob extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'workflow_analysis_jobs';

    protected $fillable = [
        'workflow_id',
        'status',
        'analyzer_prompt_version',
        'analyzer_schema_version',
        'requested_output_kind',
        'input_json',
        'result_json',
        'error_message',
        'created_by_user_id',
        'completed_at',
    ];

    protected $casts = [
        'workflow_id' => 'integer',
        'input_json' => 'array',
        'result_json' => 'array',
        'created_by_user_id' => 'integer',
        'completed_at' => 'datetime',
    ];
}
