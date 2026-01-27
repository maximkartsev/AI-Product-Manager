<?php

declare(strict_types=1);

namespace App\Tenancy\Bootstrappers;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * DatabasePoolTenancyBootstrapper
 *
 * Routes the logical `tenant` DB connection to the correct physical tenant pool connection
 * (e.g. `tenant_pool_1`, `tenant_pool_2`) based on the current tenant's `db_pool` attribute.
 *
 * This enables pooled + sharded tenancy:
 * - many tenants per physical DB
 * - multiple physical DB pools
 * - per-request/job routing via tenant metadata (central DB)
 */
class DatabasePoolTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * @var array<string, mixed>
     */
    protected array $originalTenantConnection = [];

    public function __construct()
    {
        /** @var array<string, mixed> $cfg */
        $cfg = (array) config('database.connections.tenant', []);
        $this->originalTenantConnection = $cfg;
    }

    public function bootstrap(Tenant $tenant): void
    {
        $poolConnection = (string) ($tenant->getAttribute('db_pool') ?? '');
        if ($poolConnection === '') {
            $poolConnection = (string) config('tenant_pools.default', 'tenant_pool_1');
        }

        /** @var array<string, mixed> $poolConfig */
        $poolConfig = (array) config("database.connections.{$poolConnection}", []);
        if (empty($poolConfig)) {
            throw new \RuntimeException("Unknown tenant DB pool connection: {$poolConnection}");
        }

        config(['database.connections.tenant' => $poolConfig]);

        // Purge + reconnect so the new config is applied immediately.
        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    public function revert(): void
    {
        if (!empty($this->originalTenantConnection)) {
            config(['database.connections.tenant' => $this->originalTenantConnection]);
        }

        DB::purge('tenant');
    }
}

