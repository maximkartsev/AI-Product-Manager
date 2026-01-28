<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Effect extends BaseModel
{
    use SoftDeletes;

    // Effects are a central/public catalog; avoid tenant-scoped activity logging.
    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'ai_model_id',
        'type',
        'preview_url',
        'thumbnail_url',
        'preview_video_url',
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
        'ai_model_id' => 'integer',
        'credits_cost' => 'float',
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
            'slug' => 'string|required|max:255',
            'description' => 'string|nullable',
            'ai_model_id' => 'numeric|nullable|exists:ai_models,id',
            'type' => 'string|required|max:255',
            'preview_url' => 'string|nullable|max:255',
            'thumbnail_url' => 'string|nullable|max:255',
            'preview_video_url' => 'string|nullable|max:2048',
            'parameters' => 'string|nullable',
            'default_values' => 'string|nullable',
            'credits_cost' => 'numeric|required',
            'processing_time_estimate' => 'numeric|nullable',
            'popularity_score' => 'numeric|required',
            'sort_order' => 'numeric|required',
            'is_active' => 'boolean|required',
            'is_premium' => 'boolean|required',
            'is_new' => 'boolean|required',
            'deleted_at' => 'date_format:Y-m-d H:i:s|nullable',
        ];
    }

    public function aiModel()
    {
        return $this->belongsTo(\App\Models\AiModel::class);
    }
}
