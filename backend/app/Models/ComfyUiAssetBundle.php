<?php

namespace App\Models;

class ComfyUiAssetBundle extends CentralModel
{
    protected $table = 'comfyui_asset_bundles';

    protected $fillable = [
        'workflow_id',
        'bundle_id',
        's3_prefix',
        'notes',
        'manifest',
        'active_staging_at',
        'active_production_at',
        'created_by_user_id',
        'created_by_email',
    ];

    protected $casts = [
        'workflow_id' => 'integer',
        'manifest' => 'array',
        'active_staging_at' => 'datetime',
        'active_production_at' => 'datetime',
        'created_by_user_id' => 'integer',
    ];

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function files()
    {
        return $this->belongsToMany(ComfyUiAssetFile::class, 'comfyui_asset_bundle_files', 'bundle_id', 'asset_file_id')
            ->withPivot(['target_path', 'position'])
            ->withTimestamps();
    }
}
