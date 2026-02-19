<?php

namespace App\Models;

class ComfyUiAssetAuditLog extends CentralModel
{
    public $timestamps = false;

    protected $table = 'comfyui_asset_audit_logs';

    protected $fillable = [
        'bundle_id',
        'asset_file_id',
        'event',
        'notes',
        'artifact_s3_key',
        'metadata',
        'actor_user_id',
        'actor_email',
        'created_at',
    ];

    protected $casts = [
        'bundle_id' => 'integer',
        'asset_file_id' => 'integer',
        'actor_user_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
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
