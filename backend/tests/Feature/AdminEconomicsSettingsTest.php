<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEconomicsSettingsTest extends TestCase
{
    protected static bool $prepared = false;

    private User $adminUser;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$prepared) {
            try {
                DB::connection('central')->statement(
                    'CREATE DATABASE IF NOT EXISTS tenant_pool_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
                DB::connection('central')->statement(
                    'CREATE DATABASE IF NOT EXISTS tenant_pool_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
            } catch (\Throwable $e) {
                // ignore
            }

            Artisan::call('migrate');
            Artisan::call('tenancy:pools-migrate');
            static::$prepared = true;
        }

        config(['app.url' => 'http://test.example.com']);
        url()->forceRootUrl('http://test.example.com');

        $this->resetState();
        [$this->adminUser, $this->tenant] = $this->createAdminUserTenant();
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        if (Schema::connection('central')->hasTable('economics_settings')) {
            DB::connection('central')->table('economics_settings')->truncate();
        }
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createAdminUserTenant(): array
    {
        $user = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'db_pool' => 'tenant_pool_1',
        ]);

        return [$user, $tenant];
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser);
    }

    private function adminGet(string $uri)
    {
        $this->actAsAdmin();
        return $this->getJson($uri);
    }

    private function adminPut(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->putJson($uri, $data);
    }

    public function test_get_returns_default_settings(): void
    {
        $response = $this->adminGet('/api/admin/economics/settings');

        $response->assertStatus(200);
        $response->assertJsonPath('data.token_usd_rate', 0.01);
        $response->assertJsonPath('data.spot_multiplier', null);
        $response->assertJsonPath('data.instance_type_rates.g5.xlarge', 1.006);
    }

    public function test_put_updates_settings(): void
    {
        $payload = [
            'token_usd_rate' => 0.02,
            'spot_multiplier' => 0.6,
            'instance_type_rates' => [
                'g5.xlarge' => 1.2,
                'g6e.2xlarge' => 2.5,
            ],
        ];

        $response = $this->adminPut('/api/admin/economics/settings', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.token_usd_rate', 0.02);
        $response->assertJsonPath('data.spot_multiplier', 0.6);
        $response->assertJsonPath('data.instance_type_rates.g6e.2xlarge', 2.5);

        $getResponse = $this->adminGet('/api/admin/economics/settings');
        $getResponse->assertStatus(200);
        $getResponse->assertJsonPath('data.token_usd_rate', 0.02);
        $getResponse->assertJsonPath('data.spot_multiplier', 0.6);
        $getResponse->assertJsonPath('data.instance_type_rates.g5.xlarge', 1.2);
    }

    public function test_put_rejects_invalid_payload(): void
    {
        $response = $this->adminPut('/api/admin/economics/settings', [
            'token_usd_rate' => -0.1,
            'spot_multiplier' => -1,
            'instance_type_rates' => 'nope',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'token_usd_rate',
            'spot_multiplier',
            'instance_type_rates',
        ]);
    }
}
