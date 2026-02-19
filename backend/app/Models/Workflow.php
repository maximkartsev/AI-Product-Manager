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
        'is_active',
    ];

    protected $casts = [
        'properties' => 'array',
        'is_active' => 'boolean',
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

    public function assetFiles()
    {
        return $this->hasMany(ComfyUiAssetFile::class);
    }

    public function assetBundles()
    {
        return $this->hasMany(ComfyUiAssetBundle::class);
    }
}
