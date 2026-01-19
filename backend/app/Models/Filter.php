<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Filter extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'preview_url',
        'thumbnail_url',
        'adjustments',
        'lut_file',
        'intensity_min',
        'intensity_max',
        'intensity_default',
        'sort_order',
        'is_active',
        'is_premium',
    ];

    protected $casts = [
        'adjustments' => 'array',
        'intensity_min' => 'decimal:2',
        'intensity_max' => 'decimal:2',
        'intensity_default' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:filters,slug,' . $id,
            'description' => 'string|nullable',
            'type' => 'string|nullable|max:50',
            'preview_url' => 'string|nullable|max:500',
            'thumbnail_url' => 'string|nullable|max:500',
            'adjustments' => 'array|nullable',
            'lut_file' => 'string|nullable|max:500',
            'intensity_min' => 'numeric|nullable|min:0',
            'intensity_max' => 'numeric|nullable|min:0',
            'intensity_default' => 'numeric|nullable|min:0',
            'sort_order' => 'integer|nullable',
            'is_active' => 'boolean|nullable',
            'is_premium' => 'boolean|nullable',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
