<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenTransaction extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'amount',
        'type',
        'purchase_id',
        'payment_id',
        'job_id',
        'provider_transaction_id',
        'description',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'amount' => 'integer',
        'purchase_id' => 'integer',
        'payment_id' => 'integer',
        'job_id' => 'integer',
        'metadata' => 'array',
    ];

    public static function getRules($id = null)
    {
        return [
            'tenant_id' => 'string|required|max:255',
            'user_id' => 'numeric|required',
            'amount' => 'numeric|required',
            'type' => 'string|required|max:50',
            'purchase_id' => 'numeric|nullable',
            'payment_id' => 'numeric|nullable',
            'job_id' => 'numeric|nullable',
            'provider_transaction_id' => 'string|required|max:255',
            'description' => 'string|nullable',
            'metadata' => 'array|nullable',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'job_id');
    }
}
