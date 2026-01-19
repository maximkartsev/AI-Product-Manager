<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GalleryVideo extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'video_id',
        'user_id',
        'featured_by',
        'title',
        'description',
        'thumbnail_url',
        'is_featured',
        'is_staff_pick',
        'views_count',
        'likes_count',
        'shares_count',
        'featured_at',
        'published_at',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_staff_pick' => 'boolean',
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'shares_count' => 'integer',
        'featured_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'video_id' => 'integer|required|exists:videos,id',
            'user_id' => 'integer|required|exists:users,id',
            'featured_by' => 'integer|nullable|exists:users,id',
            'title' => 'string|required|max:255',
            'description' => 'string|nullable',
            'thumbnail_url' => 'string|nullable|max:500',
            'is_featured' => 'boolean|nullable',
            'is_staff_pick' => 'boolean|nullable',
            'views_count' => 'integer|nullable|min:0',
            'likes_count' => 'integer|nullable|min:0',
            'shares_count' => 'integer|nullable|min:0',
            'featured_at' => 'date|nullable',
            'published_at' => 'date|nullable',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function featuredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'featured_by');
    }
}
