<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Effect extends CentralModel
{
    use SoftDeletes;

    // Effects are a central/public catalog; avoid tenant-scoped activity logging.
    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category_id',
        'workflow_id',
        'property_overrides',
        'tags',
        'type',
        'thumbnail_url',
        'preview_video_url',
        'credits_cost',
        'last_processing_time_seconds',
        'popularity_score',
        'is_active',
        'is_premium',
        'is_new',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'workflow_id' => 'integer',
        'property_overrides' => 'array',
        'tags' => 'array',
        'credits_cost' => 'float',
        'last_processing_time_seconds' => 'integer',
        'popularity_score' => 'integer',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
        'is_new' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:effects,slug' . ($id ? ',' . $id : ''),
            'description' => 'string|nullable',
            'category_id' => 'numeric|nullable|exists:categories,id',
            'workflow_id' => 'numeric|nullable|exists:workflows,id',
            'property_overrides' => 'array|nullable',
            'tags' => 'array|nullable',
            'tags.*' => 'string|max:64',
            'type' => 'string|required|max:255',
            'thumbnail_url' => 'string|nullable|max:2048',
            'preview_video_url' => 'string|nullable|max:2048',
            'credits_cost' => 'numeric|required',
            'popularity_score' => 'numeric|required',
            'is_active' => 'boolean|required',
            'is_premium' => 'boolean|required',
            'is_new' => 'boolean|required',
            'deleted_at' => 'date_format:Y-m-d H:i:s|nullable',
        ];
    }

    public function category()
    {
        return $this->belongsTo(\App\Models\Category::class);
    }

    public function workflow()
    {
        return $this->belongsTo(\App\Models\Workflow::class);
    }
}
