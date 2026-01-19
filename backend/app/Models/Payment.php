<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_id',
        'provider',
        'method',
        'status',
        'amount',
        'currency',
        'external_id',
        'external_status',
        'provider_response',
        'card_brand',
        'card_last_four',
        'failure_code',
        'failure_message',
        'paid_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_response' => 'array',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'purchase_id' => 'integer|required|exists:purchases,id',
            'provider' => 'string|nullable|max:50',
            'method' => 'string|nullable|max:50',
            'status' => 'string|nullable|max:50',
            'amount' => 'numeric|required|min:0',
            'currency' => 'string|nullable|max:10',
            'external_id' => 'string|nullable|max:255',
            'external_status' => 'string|nullable|max:100',
            'provider_response' => 'array|nullable',
            'card_brand' => 'string|nullable|max:50',
            'card_last_four' => 'string|nullable|max:4',
            'failure_code' => 'string|nullable|max:100',
            'failure_message' => 'string|nullable',
            'paid_at' => 'date|nullable',
            'refunded_at' => 'date|nullable',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
