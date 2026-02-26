<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLoadTestScenariosTest extends TestCase
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
        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_create_load_test_scenario_with_fault_and_economics_fields(): void
    {
        $response = $this->postJson('/api/admin/studio/load-test-scenarios', [
            'name' => 'Scenario ' . uniqid(),
            'description' => 'Pre-release scenario',
            'is_active' => true,
            'stages' => [
                [
                    'stage_order' => 0,
                    'stage_type' => 'spike',
                    'duration_seconds' => 300,
                    'target_rpm' => 100,
                    'fault_enabled' => true,
                    'fault_kind' => 'instance_termination',
                    'fault_interruption_rate' => 0.2,
                    'fault_target_scope' => 'spot_only',
                    'fault_method' => 'fis',
                    'fault_notice_seconds' => 120,
                    'economics_spot_discount_override' => 0.6,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stages.0.fault_enabled', true)
            ->assertJsonPath('data.stages.0.fault_method', 'fis')
            ->assertJsonPath('data.stages.0.economics_spot_discount_override', 0.6);
    }

    public function test_rejects_non_aws_fault_method(): void
    {
        $response = $this->postJson('/api/admin/studio/load-test-scenarios', [
            'name' => 'Scenario ' . uniqid(),
            'stages' => [
                [
                    'stage_type' => 'steady',
                    'duration_seconds' => 120,
                    'target_rpm' => 10,
                    'fault_enabled' => true,
                    'fault_kind' => 'instance_termination',
                    'fault_method' => 'custom_script',
                ],
            ],
        ]);

        $response->assertStatus(422);
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

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('load_test_stages')->truncate();
        DB::connection('central')->table('load_test_scenarios')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
