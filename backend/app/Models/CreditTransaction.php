<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CreditTransaction extends BaseModel
{
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'reference_type',
        'reference_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
    ];

    public static function getRules($id = null)
    {
        return [
            'user_id' => 'integer|required|exists:users,id',
            'type' => 'string|required|max:50',
            'amount' => 'numeric|required',
            'balance_before' => 'numeric|required',
            'balance_after' => 'numeric|required',
            'description' => 'string|nullable',
            'reference_type' => 'string|nullable|max:100',
            'reference_id' => 'integer|nullable',
            'metadata' => 'array|nullable',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
