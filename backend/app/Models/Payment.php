<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends CentralModel
{
    public bool $enableLoggingModelsEvents = false;

    protected $fillable = [
        'purchase_id',
        'transaction_id',
        'status',
        'amount',
        'currency',
        'payment_gateway',
        'processed_at',
        'metadata',
    ];

    protected $casts = [
        'purchase_id' => 'integer',
        'amount' => 'float',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    public static function getRules($id = null)
    {
        return [
            'purchase_id' => 'numeric|required|exists:purchases,id',
            'transaction_id' => 'string|required|max:255',
            'status' => 'string|required|max:50',
            'amount' => 'numeric|required',
            'currency' => 'string|required|max:3',
            'payment_gateway' => 'string|required|max:50',
            'processed_at' => 'date_format:Y-m-d H:i:s|nullable',
            'metadata' => 'array|nullable',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
