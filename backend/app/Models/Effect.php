<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Effect extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'ai_model_id',
        'type',
        'preview_url',
        'thumbnail_url',
        'parameters',
        'default_values',
        'credits_cost',
        'processing_time_estimate',
        'popularity_score',
        'sort_order',
        'is_active',
        'is_premium',
        'is_new',
    ];

    protected $casts = [
        'parameters' => 'array',
        'default_values' => 'array',
        'credits_cost' => 'decimal:2',
        'processing_time_estimate' => 'integer',
        'popularity_score' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
        'is_new' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:effects,slug,' . $id,
            'description' => 'string|nullable',
            'ai_model_id' => 'integer|nullable|exists:ai_models,id',
            'type' => 'string|nullable|max:50',
            'preview_url' => 'string|nullable|max:500',
            'thumbnail_url' => 'string|nullable|max:500',
            'parameters' => 'array|nullable',
            'default_values' => 'array|nullable',
            'credits_cost' => 'numeric|nullable|min:0',
            'processing_time_estimate' => 'integer|nullable|min:0',
            'popularity_score' => 'integer|nullable|min:0',
            'sort_order' => 'integer|nullable',
            'is_active' => 'boolean|nullable',
            'is_premium' => 'boolean|nullable',
            'is_new' => 'boolean|nullable',
        ];
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function styles(): HasMany
    {
        return $this->hasMany(Style::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_effect')->withTimestamps();
    }

    public function algorithms(): BelongsToMany
    {
        return $this->belongsToMany(Algorithm::class, 'algorithm_effect')
            ->withPivot(['sort_order', 'config'])
            ->withTimestamps();
    }
}
