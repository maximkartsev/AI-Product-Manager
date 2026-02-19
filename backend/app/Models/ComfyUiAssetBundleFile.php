<?php

namespace App\Models;

class ComfyUiAssetBundleFile extends CentralModel
{
    protected $table = 'comfyui_asset_bundle_files';

    protected $fillable = [
        'bundle_id',
        'asset_file_id',
        'target_path',
        'position',
    ];

    protected $casts = [
        'bundle_id' => 'integer',
        'asset_file_id' => 'integer',
        'position' => 'integer',
    ];

    public function bundle()
    {
        return $this->belongsTo(ComfyUiAssetBundle::class, 'bundle_id');
    }

    public function assetFile()
    {
        return $this->belongsTo(ComfyUiAssetFile::class, 'asset_file_id');
    }
}
