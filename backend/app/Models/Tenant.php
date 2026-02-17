<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant (central DB).
 *
 * In this project each user maps 1:1 to a tenant and tenant-private data is stored in pooled
 * tenant databases (multiple tenants per DB). The pool routing key is stored in `db_pool`.
 */
class Tenant extends BaseTenant
{
    use HasDomains;

    /**
     * Force the central DB connection for tenancy metadata.
     */
    protected $connection = 'central';

    protected $fillable = [
        'id',
        'user_id',
        'db_pool',
    ];

    /**
     * Tell stancl/tenancy which attributes have dedicated columns (instead of JSON `data`).
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'user_id',
            'db_pool',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

