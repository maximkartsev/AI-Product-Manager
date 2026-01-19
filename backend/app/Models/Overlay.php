<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Overlay extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'file_path',
        'preview_url',
        'thumbnail_url',
        'blend_mode',
        'opacity_default',
        'position_default',
        'scale_default',
        'is_animated',
        'duration',
        'sort_order',
        'is_active',
        'is_premium',
    ];

    protected $casts = [
        'opacity_default' => 'decimal:2',
        'position_default' => 'array',
        'scale_default' => 'array',
        'is_animated' => 'boolean',
        'duration' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:overlays,slug,' . $id,
            'description' => 'string|nullable',
            'type' => 'string|nullable|max:50',
            'file_path' => 'string|required|max:500',
            'preview_url' => 'string|nullable|max:500',
            'thumbnail_url' => 'string|nullable|max:500',
            'blend_mode' => 'string|nullable|max:50',
            'opacity_default' => 'numeric|nullable|min:0|max:100',
            'position_default' => 'array|nullable',
            'scale_default' => 'array|nullable',
            'is_animated' => 'boolean|nullable',
            'duration' => 'integer|nullable|min:0',
            'sort_order' => 'integer|nullable',
            'is_active' => 'boolean|nullable',
            'is_premium' => 'boolean|nullable',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables')->withTimestamps();
    }
}
