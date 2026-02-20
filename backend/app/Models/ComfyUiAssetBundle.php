<?php

namespace App\Models;

class ComfyUiAssetBundle extends CentralModel
{
    protected $table = 'comfyui_asset_bundles';

    protected $fillable = [
        'bundle_id',
        'name',
        's3_prefix',
        'notes',
        'manifest',
        'created_by_user_id',
        'created_by_email',
    ];

    protected $casts = [
        'manifest' => 'array',
        'created_by_user_id' => 'integer',
    ];

    public function files()
    {
        return $this->belongsToMany(ComfyUiAssetFile::class, 'comfyui_asset_bundle_files', 'bundle_id', 'asset_file_id')
            ->withPivot(['target_path', 'position'])
            ->withTimestamps();
    }
}
