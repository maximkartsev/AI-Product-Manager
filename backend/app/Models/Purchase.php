<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Purchase extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'package_id',
        'total',
        'subtotal',
        'original_amount',
        'applied_discount_amount',
        'total_amount',
        'status',
        'external_transaction_id',
        'processed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'package_id' => 'integer',
        'total' => 'float',
        'subtotal' => 'float',
        'original_amount' => 'float',
        'applied_discount_amount' => 'float',
        'total_amount' => 'float',
        'processed_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'tenant_id' => 'string|required|max:255',
            'user_id' => 'numeric|required|exists:users,id',
            'package_id' => 'numeric|nullable|exists:packages,id',
            'total' => 'numeric|required',
            'subtotal' => 'numeric|required',
            'original_amount' => 'numeric|required',
            'applied_discount_amount' => 'numeric|required',
            'total_amount' => 'numeric|required',
            'status' => 'string|required|max:50',
            'external_transaction_id' => 'string|nullable|max:255',
            'processed_at' => 'date_format:Y-m-d H:i:s|nullable',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
