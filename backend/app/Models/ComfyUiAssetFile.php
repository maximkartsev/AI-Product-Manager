<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class ComfyUiAssetFile extends CentralModel
{
    use SoftDeletes;

    protected $table = 'comfyui_asset_files';

    protected $fillable = [
        'kind',
        'original_filename',
        's3_key',
        'content_type',
        'size_bytes',
        'sha256',
        'notes',
        'uploaded_at',
        'metadata',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'uploaded_at' => 'datetime',
        'metadata' => 'array',
    ];
}
