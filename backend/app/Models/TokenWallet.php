<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenWallet extends TenantModel
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'balance',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'balance' => 'integer',
    ];

    public static function getRules($id = null)
    {
        return [
            'tenant_id' => 'string|required|max:255',
            'user_id' => 'numeric|required',
            'balance' => 'numeric|required',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
