<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class File extends TenantModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'disk',
        'path',
        'url',
        'mime_type',
        'size',
        'original_filename',
        'file_hash',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'size' => 'integer',
        'metadata' => 'array',
    ];
}
