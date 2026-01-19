<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tier extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'monthly_price',
        'annual_price',
        'credits_per_month',
        'max_video_length',
        'max_resolution',
        'max_exports_per_day',
        'features',
        'sort_order',
        'is_active',
        'is_featured',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'annual_price' => 'decimal:2',
        'credits_per_month' => 'integer',
        'max_video_length' => 'integer',
        'max_resolution' => 'integer',
        'max_exports_per_day' => 'integer',
        'features' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:tiers,slug,' . $id,
            'description' => 'string|nullable',
            'monthly_price' => 'numeric|nullable|min:0',
            'annual_price' => 'numeric|nullable|min:0',
            'credits_per_month' => 'integer|nullable|min:0',
            'max_video_length' => 'integer|nullable|min:0',
            'max_resolution' => 'integer|nullable|min:0',
            'max_exports_per_day' => 'integer|nullable|min:0',
            'features' => 'array|nullable',
            'sort_order' => 'integer|nullable',
            'is_active' => 'boolean|nullable',
            'is_featured' => 'boolean|nullable',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
