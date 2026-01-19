<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'original_name',
        'path',
        'disk',
        'mime_type',
        'extension',
        'size',
        'type',
        'width',
        'height',
        'duration',
        'fps',
        'codec',
        'bitrate',
        'metadata',
        'thumbnail_path',
        'status',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'fps' => 'decimal:2',
        'bitrate' => 'integer',
        'metadata' => 'array',
    ];

    public static function getRules($id = null)
    {
        return [
            'user_id' => 'integer|required|exists:users,id',
            'name' => 'string|required|max:255',
            'original_name' => 'string|required|max:255',
            'path' => 'string|required|max:500',
            'disk' => 'string|nullable|max:50',
            'mime_type' => 'string|nullable|max:100',
            'extension' => 'string|nullable|max:20',
            'size' => 'integer|nullable|min:0',
            'type' => 'string|nullable|max:50',
            'width' => 'integer|nullable|min:0',
            'height' => 'integer|nullable|min:0',
            'duration' => 'integer|nullable|min:0',
            'fps' => 'numeric|nullable|min:0',
            'codec' => 'string|nullable|max:50',
            'bitrate' => 'integer|nullable|min:0',
            'metadata' => 'array|nullable',
            'thumbnail_path' => 'string|nullable|max:500',
            'status' => 'string|nullable|max:50',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class, 'source_file_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }
}
