<?php

namespace App\Models;

class ComfyUiGpuFleet extends CentralModel
{
    protected $table = 'comfyui_gpu_fleets';

    protected $fillable = [
        'stage',
        'slug',
        'name',
        'instance_types',
        'max_size',
        'warmup_seconds',
        'backlog_target',
        'scale_to_zero_minutes',
        'ami_ssm_parameter',
        'active_bundle_id',
        'active_bundle_s3_prefix',
    ];

    protected $casts = [
        'instance_types' => 'array',
        'max_size' => 'integer',
        'warmup_seconds' => 'integer',
        'backlog_target' => 'integer',
        'scale_to_zero_minutes' => 'integer',
        'active_bundle_id' => 'integer',
    ];

    public function activeBundle()
    {
        return $this->belongsTo(ComfyUiAssetBundle::class, 'active_bundle_id');
    }

    public function workflows()
    {
        return $this->belongsToMany(Workflow::class, 'comfyui_workflow_fleets', 'fleet_id', 'workflow_id')
            ->withPivot(['stage', 'assigned_at', 'assigned_by_user_id', 'assigned_by_email'])
            ->withTimestamps();
    }
}
