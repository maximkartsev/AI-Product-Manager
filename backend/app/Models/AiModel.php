<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiModel extends BaseModel
{
    use SoftDeletes;

    protected $table = 'ai_models';

    protected $fillable = [
        'name',
        'slug',
        'version',
        'description',
        'provider',
        'type',
        'credits_per_use',
        'processing_time_estimate',
        'input_requirements',
        'output_specs',
        'config',
        'is_active',
        'is_premium',
    ];

    protected $casts = [
        'credits_per_use' => 'decimal:2',
        'processing_time_estimate' => 'integer',
        'input_requirements' => 'array',
        'output_specs' => 'array',
        'config' => 'array',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:ai_models,slug,' . $id,
            'version' => 'string|nullable|max:50',
            'description' => 'string|nullable',
            'provider' => 'string|nullable|max:100',
            'type' => 'string|nullable|max:50',
            'credits_per_use' => 'numeric|nullable|min:0',
            'processing_time_estimate' => 'integer|nullable|min:0',
            'input_requirements' => 'array|nullable',
            'output_specs' => 'array|nullable',
            'config' => 'array|nullable',
            'is_active' => 'boolean|nullable',
            'is_premium' => 'boolean|nullable',
        ];
    }

    public function effects(): HasMany
    {
        return $this->hasMany(Effect::class, 'ai_model_id');
    }
}
