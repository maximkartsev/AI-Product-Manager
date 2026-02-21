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

    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $existing = $activity->getAttribute('tenant_id');
            if (is_string($existing) && $existing !== '') {
                return;
            }

            $tenantId = tenant()?->getKey();
            $activity->setAttribute('tenant_id', $tenantId ? (string) $tenantId : 'system');
        });
    }
}

