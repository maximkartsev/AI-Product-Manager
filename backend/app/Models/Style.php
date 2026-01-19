<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Style extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'effect_id',
        'preview_url',
        'thumbnail_url',
        'parameters',
        'intensity_min',
        'intensity_max',
        'intensity_default',
        'credits_modifier',
        'sort_order',
        'is_active',
        'is_premium',
    ];

    protected $casts = [
        'parameters' => 'array',
        'intensity_min' => 'decimal:2',
        'intensity_max' => 'decimal:2',
        'intensity_default' => 'decimal:2',
        'credits_modifier' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:styles,slug,' . $id,
            'description' => 'string|nullable',
            'effect_id' => 'integer|required|exists:effects,id',
            'preview_url' => 'string|nullable|max:500',
            'thumbnail_url' => 'string|nullable|max:500',
            'parameters' => 'array|nullable',
            'intensity_min' => 'numeric|nullable|min:0',
            'intensity_max' => 'numeric|nullable|min:0',
            'intensity_default' => 'numeric|nullable|min:0',
            'credits_modifier' => 'numeric|nullable|min:0',
            'sort_order' => 'integer|nullable',
            'is_active' => 'boolean|nullable',
            'is_premium' => 'boolean|nullable',
        ];
    }

    public function effect(): BelongsTo
    {
        return $this->belongsTo(Effect::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
