<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $usersData = [
            [
                'name' => 'Test User',
                'email' => 'test@test.com',
                'password' => bcrypt('123456'),
            ],
            [
                'name' => 'Admin User',
                'email' => 'test@gmail.com',
                'password' => bcrypt('654321'),
                'is_admin' => true,
            ],
        ];

        foreach ($usersData as $input) {
            $user = User::query()->where('email', $input['email'])->first();
            if (!$user) {
                $user = User::factory()->create($input);
            }

            if (array_key_exists('is_admin', $input) && (bool) $user->is_admin !== (bool) $input['is_admin']) {
                $user->is_admin = (bool) $input['is_admin'];
                $user->save();
            }

            $this->ensureTenantForUser($user);
        }
    }

    private function ensureTenantForUser(User $user): Tenant
    {
        $existing = Tenant::query()->where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $base = Str::slug((string) $user->name);
        if ($base === '') {
            $base = 'user';
        }

        $tenantId = $base . '-' . $user->id;
        $attempts = 0;
        while (Tenant::query()->whereKey($tenantId)->exists()) {
            $attempts++;
            $tenantId = $base . '-' . $user->id . '-' . Str::lower(Str::random(6));
            if ($attempts > 10) {
                throw new \RuntimeException('Unable to allocate a unique tenant id.');
            }
        }

        $dbPool = (string) config('tenant_pools.default', 'tenant_pool_1');

        /** @var Tenant $tenant */
        $tenant = Tenant::create([
            'id' => $tenantId,
            'user_id' => $user->id,
            'db_pool' => $dbPool,
        ]);

        $baseDomain = (string) env('TENANCY_BASE_DOMAIN', 'localhost');
        $tenantDomain = "{$tenantId}.{$baseDomain}";

        $tenant->domains()->firstOrCreate([
            'domain' => $tenantDomain,
        ]);

        return $tenant;
    }
}

