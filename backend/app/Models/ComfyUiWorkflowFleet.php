<?php

namespace App\Models;

class ComfyUiWorkflowFleet extends CentralModel
{
    protected $table = 'comfyui_workflow_fleets';

    protected $fillable = [
        'workflow_id',
        'fleet_id',
        'stage',
        'assigned_at',
        'assigned_by_user_id',
        'assigned_by_email',
    ];

    protected $casts = [
        'workflow_id' => 'integer',
        'fleet_id' => 'integer',
        'assigned_at' => 'datetime',
        'assigned_by_user_id' => 'integer',
    ];

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function fleet()
    {
        return $this->belongsTo(ComfyUiGpuFleet::class, 'fleet_id');
    }
}
