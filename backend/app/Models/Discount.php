<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'type',
        'value',
        'min_purchase',
        'max_discount',
        'usage_limit',
        'usage_count',
        'per_user_limit',
        'starts_at',
        'expires_at',
        'applicable_to',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
        'per_user_limit' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'applicable_to' => 'array',
        'is_active' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'name' => 'string|required|max:255',
            'code' => 'string|required|max:100|unique:discounts,code,' . $id,
            'description' => 'string|nullable',
            'type' => 'string|nullable|max:50',
            'value' => 'numeric|required|min:0',
            'min_purchase' => 'numeric|nullable|min:0',
            'max_discount' => 'numeric|nullable|min:0',
            'usage_limit' => 'integer|nullable|min:0',
            'usage_count' => 'integer|nullable|min:0',
            'per_user_limit' => 'integer|nullable|min:0',
            'starts_at' => 'date|nullable',
            'expires_at' => 'date|nullable|after_or_equal:starts_at',
            'applicable_to' => 'array|nullable',
            'is_active' => 'boolean|nullable',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_discount')
            ->withPivot(['usage_count', 'first_used_at', 'last_used_at'])
            ->withTimestamps();
    }
}
