<?php

namespace App\Models;

class WorkflowRevision extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $table = 'workflow_revisions';

    protected $fillable = [
        'workflow_id',
        'comfyui_workflow_path',
        'snapshot_json',
        'created_by_user_id',
    ];

    protected $casts = [
        'workflow_id' => 'integer',
        'snapshot_json' => 'array',
        'created_by_user_id' => 'integer',
    ];

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }
}
