<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class ComfyUiAssetFile extends CentralModel
{
    use SoftDeletes;

    protected $table = 'comfyui_asset_files';

    protected $fillable = [
        'workflow_id',
        'kind',
        'original_filename',
        's3_key',
        'content_type',
        'size_bytes',
        'sha256',
        'uploaded_at',
        'metadata',
    ];

    protected $casts = [
        'workflow_id' => 'integer',
        'size_bytes' => 'integer',
        'uploaded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }
}
