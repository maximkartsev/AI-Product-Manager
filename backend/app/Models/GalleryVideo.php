<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class GalleryVideo extends CentralModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'video_id',
        'effect_id',
        'title',
        'is_public',
        'tags',
        'processed_file_url',
        'thumbnail_url',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'video_id' => 'integer',
        'effect_id' => 'integer',
        'is_public' => 'boolean',
        'tags' => 'array',
    ];
}
