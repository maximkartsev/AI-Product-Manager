<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'package_id',
        'discount_id',
        'type',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'currency',
        'credits_granted',
        'invoice_number',
        'metadata',
        'completed_at',
        'refunded_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'credits_granted' => 'integer',
        'metadata' => 'array',
        'completed_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'user_id' => 'integer|required|exists:users,id',
            'package_id' => 'integer|nullable|exists:packages,id',
            'discount_id' => 'integer|nullable|exists:discounts,id',
            'type' => 'string|nullable|max:50',
            'status' => 'string|nullable|max:50',
            'subtotal' => 'numeric|required|min:0',
            'discount_amount' => 'numeric|nullable|min:0',
            'tax_amount' => 'numeric|nullable|min:0',
            'total' => 'numeric|required|min:0',
            'currency' => 'string|nullable|max:10',
            'credits_granted' => 'integer|nullable|min:0',
            'invoice_number' => 'string|nullable|max:100|unique:purchases,invoice_number,' . $id,
            'metadata' => 'array|nullable',
            'completed_at' => 'date|nullable',
            'refunded_at' => 'date|nullable',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
