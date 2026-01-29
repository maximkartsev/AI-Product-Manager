<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Video extends TenantModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'effect_id',
        'original_file_id',
        'processed_file_id',
        'title',
        'status',
        'is_public',
        'processing_details',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'effect_id' => 'integer',
        'original_file_id' => 'integer',
        'processed_file_id' => 'integer',
        'is_public' => 'boolean',
        'processing_details' => 'array',
        'expires_at' => 'datetime',
    ];
}
