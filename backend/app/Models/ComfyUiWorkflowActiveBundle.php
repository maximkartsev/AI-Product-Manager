<?php

namespace App\Models;

class ComfyUiWorkflowActiveBundle extends CentralModel
{
    protected $table = 'comfyui_workflow_active_bundles';

    protected $fillable = [
        'workflow_id',
        'stage',
        'bundle_id',
        'bundle_s3_prefix',
        'activated_at',
        'activated_by_user_id',
        'activated_by_email',
        'notes',
    ];

    protected $casts = [
        'workflow_id' => 'integer',
        'bundle_id' => 'integer',
        'activated_at' => 'datetime',
        'activated_by_user_id' => 'integer',
    ];

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function bundle()
    {
        return $this->belongsTo(ComfyUiAssetBundle::class, 'bundle_id');
    }
}
