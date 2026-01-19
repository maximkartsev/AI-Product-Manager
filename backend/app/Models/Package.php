<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'price',
        'credits',
        'bonus_credits',
        'features',
        'validity_days',
        'sort_order',
        'is_active',
        'is_featured',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'credits' => 'integer',
        'bonus_credits' => 'decimal:2',
        'features' => 'array',
        'validity_days' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'slug' => 'string|required|max:255|unique:packages,slug,' . $id,
            'description' => 'string|nullable',
            'type' => 'string|nullable|max:50',
            'price' => 'numeric|required|min:0',
            'credits' => 'integer|nullable|min:0',
            'bonus_credits' => 'numeric|nullable|min:0',
            'features' => 'array|nullable',
            'validity_days' => 'integer|nullable|min:0',
            'sort_order' => 'integer|nullable',
            'is_active' => 'boolean|nullable',
            'is_featured' => 'boolean|nullable',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }
}
