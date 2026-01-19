<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Video extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'source_file_id',
        'effect_id',
        'style_id',
        'filter_id',
        'overlay_id',
        'watermark_id',
        'title',
        'description',
        'status',
        'effect_parameters',
        'style_parameters',
        'filter_parameters',
        'overlay_parameters',
        'watermark_parameters',
        'timeline',
        'credits_used',
        'processing_time',
        'preview_url',
        'thumbnail_url',
        'views_count',
        'likes_count',
        'is_public',
        'processing_started_at',
        'processing_completed_at',
    ];

    protected $casts = [
        'effect_parameters' => 'array',
        'style_parameters' => 'array',
        'filter_parameters' => 'array',
        'overlay_parameters' => 'array',
        'watermark_parameters' => 'array',
        'timeline' => 'array',
        'credits_used' => 'decimal:2',
        'processing_time' => 'integer',
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'is_public' => 'boolean',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'user_id' => 'integer|required|exists:users,id',
            'source_file_id' => 'integer|required|exists:files,id',
            'effect_id' => 'integer|nullable|exists:effects,id',
            'style_id' => 'integer|nullable|exists:styles,id',
            'filter_id' => 'integer|nullable|exists:filters,id',
            'overlay_id' => 'integer|nullable|exists:overlays,id',
            'watermark_id' => 'integer|nullable|exists:watermarks,id',
            'title' => 'string|required|max:255',
            'description' => 'string|nullable',
            'status' => 'string|nullable|max:50',
            'effect_parameters' => 'array|nullable',
            'style_parameters' => 'array|nullable',
            'filter_parameters' => 'array|nullable',
            'overlay_parameters' => 'array|nullable',
            'watermark_parameters' => 'array|nullable',
            'timeline' => 'array|nullable',
            'credits_used' => 'numeric|nullable|min:0',
            'processing_time' => 'integer|nullable|min:0',
            'preview_url' => 'string|nullable|max:500',
            'thumbnail_url' => 'string|nullable|max:500',
            'views_count' => 'integer|nullable|min:0',
            'likes_count' => 'integer|nullable|min:0',
            'is_public' => 'boolean|nullable',
            'processing_started_at' => 'date|nullable',
            'processing_completed_at' => 'date|nullable',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'source_file_id');
    }

    public function effect(): BelongsTo
    {
        return $this->belongsTo(Effect::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function filter(): BelongsTo
    {
        return $this->belongsTo(Filter::class);
    }

    public function overlay(): BelongsTo
    {
        return $this->belongsTo(Overlay::class);
    }

    public function watermark(): BelongsTo
    {
        return $this->belongsTo(Watermark::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    public function galleryVideo(): HasOne
    {
        return $this->hasOne(GalleryVideo::class);
    }
}
