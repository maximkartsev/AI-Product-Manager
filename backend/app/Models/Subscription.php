<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'tier_id',
        'status',
        'billing_cycle',
        'price',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'cancelled_at',
        'cancellation_reason',
        'payment_method',
        'external_id',
        'metadata',
        'auto_renew',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
        'auto_renew' => 'boolean',
    ];

    public static function getRules($id = null)
    {
        return [
            'user_id' => 'integer|required|exists:users,id',
            'tier_id' => 'integer|required|exists:tiers,id',
            'status' => 'string|nullable|max:50',
            'billing_cycle' => 'string|nullable|max:50',
            'price' => 'numeric|required|min:0',
            'starts_at' => 'date|required',
            'ends_at' => 'date|nullable|after_or_equal:starts_at',
            'trial_ends_at' => 'date|nullable',
            'cancelled_at' => 'date|nullable',
            'cancellation_reason' => 'string|nullable|max:255',
            'payment_method' => 'string|nullable|max:100',
            'external_id' => 'string|nullable|max:255',
            'metadata' => 'array|nullable',
            'auto_renew' => 'boolean|nullable',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }
}
