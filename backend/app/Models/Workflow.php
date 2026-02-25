<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Workflow extends CentralModel
{
    use SoftDeletes;

    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'comfyui_workflow_path',
        'properties',
        'output_node_id',
        'output_extension',
        'output_mime_type',
        'workload_kind',
        'work_units_property_key',
        'slo_p95_wait_seconds',
        'slo_video_seconds_per_processing_second_p95',
        'partner_cost_per_work_unit',
        'is_active',
    ];

    protected $casts = [
        'properties' => 'array',
        'is_active' => 'boolean',
        'slo_p95_wait_seconds' => 'integer',
        'slo_video_seconds_per_processing_second_p95' => 'float',
        'partner_cost_per_work_unit' => 'float',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:workflows,slug' . ($id ? ',' . $id : ''),
            'description' => 'string|nullable',
            'comfyui_workflow_path' => 'string|nullable|max:2048',
            'properties' => 'array|nullable',
            'output_node_id' => 'string|nullable|max:64',
            'output_extension' => 'string|nullable|max:16',
            'output_mime_type' => 'string|nullable|max:255',
            'workload_kind' => 'string|nullable|in:image,video',
            'work_units_property_key' => 'string|nullable|max:255',
            'slo_p95_wait_seconds' => 'integer|nullable|min:1',
            'slo_video_seconds_per_processing_second_p95' => 'numeric|nullable|min:0',
            'partner_cost_per_work_unit' => 'numeric|nullable|min:0',
            'is_active' => 'boolean',
        ];
    }

    public function effects()
    {
        return $this->hasMany(Effect::class);
    }

    public function workers()
    {
        return $this->belongsToMany(ComfyUiWorker::class, 'worker_workflows', 'workflow_id', 'worker_id');
    }

    public function fleets()
    {
        return $this->belongsToMany(ComfyUiGpuFleet::class, 'comfyui_workflow_fleets', 'workflow_id', 'fleet_id')
            ->withPivot(['stage', 'assigned_at', 'assigned_by_user_id', 'assigned_by_email'])
            ->withTimestamps();
    }
}
