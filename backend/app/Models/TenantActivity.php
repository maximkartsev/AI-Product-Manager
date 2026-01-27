<?php

namespace App\Models;

use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * TenantActivity
 *
 * Custom Activitylog model that is tenant-aware inside pooled tenant databases.
 */
class TenantActivity extends SpatieActivity
{
    use BelongsToTenant;

    protected $connection = 'tenant';
}

