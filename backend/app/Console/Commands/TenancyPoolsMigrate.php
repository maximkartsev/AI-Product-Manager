<?php

namespace App\Console\Commands;

use App\AI\AgentCommandDefinitionProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TenancyPoolsMigrate extends Command implements AgentCommandDefinitionProvider
{
    protected $signature = 'tenancy:pools-migrate
                            {--central : Migrate the central database}
                            {--tenant : Migrate tenant pool databases}
                            {--fresh : Use migrate:fresh instead of migrate}
                            {--pools= : Comma-separated list of tenant pool connection names (defaults to config tenant_pools.connections)}';

    protected $description = 'Run migrations for the central DB and all tenant DB pools.';

    public static function getAgentCommandDefinition(): array
    {
        return [
            'name' => 'tenancy:pools-migrate',
            'category' => 'db',
            'purpose' => 'Run migrations for central DB and all tenant DB pools (pooled/sharded tenancy).',
            'usage' => 'php artisan tenancy:pools-migrate {--central} {--tenant} {--fresh} {--pools=tenant_pool_1,tenant_pool_2}',
            'notes' => [
                'Use without flags to migrate both central and tenant pools.',
                'Tenant migrations live in database/migrations/tenant and are applied to each pool connection.',
            ],
        ];
    }

    public function handle(): int
    {
        $doCentral = (bool) $this->option('central');
        $doTenant = (bool) $this->option('tenant');

        // Default behavior: do both unless explicitly restricted.
        if (!$doCentral && !$doTenant) {
            $doCentral = true;
            $doTenant = true;
        }

        $fresh = (bool) $this->option('fresh');

        /** @var string|null $poolsOpt */
        $poolsOpt = $this->option('pools');
        $pools = [];
        if (is_string($poolsOpt) && trim($poolsOpt) !== '') {
            $pools = array_values(array_filter(array_map('trim', explode(',', $poolsOpt))));
        } else {
            $pools = (array) config('tenant_pools.connections', []);
        }

        if ($doCentral) {
            $this->info('Migrating central DB...');
            $command = $fresh ? 'migrate:fresh' : 'migrate';

            $exit = Artisan::call($command, [
                '--database' => 'central',
                '--force' => true,
            ]);

            $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS) {
                return $exit;
            }
        }

        if ($doTenant) {
            if (empty($pools)) {
                $this->warn('No tenant pools configured (tenant_pools.connections is empty).');
                return self::SUCCESS;
            }

            foreach ($pools as $poolConnection) {
                $poolConnection = (string) $poolConnection;
                if ($poolConnection === '') {
                    continue;
                }

                $this->info("Migrating tenant pool DB: {$poolConnection} ...");
                $command = $fresh ? 'migrate:fresh' : 'migrate';

                $exit = Artisan::call($command, [
                    '--database' => $poolConnection,
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                $this->output->write(Artisan::output());
                if ($exit !== self::SUCCESS) {
                    return $exit;
                }
            }
        }

        return self::SUCCESS;
    }
}

