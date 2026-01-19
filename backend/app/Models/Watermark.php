<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Watermark extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'file_path',
        'text_content',
        'font_family',
        'font_size',
        'font_color',
        'position',
        'opacity',
        'scale',
        'margin_x',
        'margin_y',
        'is_default',
    ];

    protected $casts = [
        'font_size' => 'integer',
        'opacity' => 'decimal:2',
        'scale' => 'decimal:2',
        'margin_x' => 'integer',
        'margin_y' => 'integer',
        'is_default' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'user_id' => 'integer|required|exists:users,id',
            'name' => 'string|required|max:255',
            'type' => 'string|nullable|max:50',
            'file_path' => 'string|nullable|max:500',
            'text_content' => 'string|nullable',
            'font_family' => 'string|nullable|max:100',
            'font_size' => 'integer|nullable|min:1',
            'font_color' => 'string|nullable|max:50',
            'position' => 'string|nullable|max:50',
            'opacity' => 'numeric|nullable|min:0|max:100',
            'scale' => 'numeric|nullable|min:0',
            'margin_x' => 'integer|nullable|min:0',
            'margin_y' => 'integer|nullable|min:0',
            'is_default' => 'boolean|nullable',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
