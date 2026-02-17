<?php

namespace App\Models;

use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * TenantModel
 *
 * Base model for tenant-private entities stored in pooled tenant databases.
 * Uses `BelongsToTenant` to scope queries by `tenant_id` inside a pooled DB.
 */
abstract class TenantModel extends BaseModel
{
    use BelongsToTenant;

    protected $connection = 'tenant';
}

