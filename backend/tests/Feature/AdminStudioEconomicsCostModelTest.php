<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudioEconomicsCostModelTest extends TestCase
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

    public function test_cost_model_returns_1_10_100_run_economics_breakdown(): void
    {
        $response = $this->postJson('/api/admin/studio/economics/cost-model', [
            'startup_seconds' => 120,
            'busy_seconds_per_run' => 30,
            'idle_seconds_after_batch' => 60,
            'compute_rate_usd_per_second' => 0.01,
            'partner_cost_usd_per_run' => 0.2,
            'revenue_usd_per_run' => 1.0,
            'run_counts' => [1, 10, 100],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assumptions.startup_seconds', 120)
            ->assertJsonPath('data.assumptions.compute_rate_usd_per_second', 0.01);

        $models = collect($response->json('data.models'))->keyBy('run_count');
        $this->assertTrue($models->has(1));
        $this->assertTrue($models->has(10));
        $this->assertTrue($models->has(100));

        $oneRun = $models->get(1);
        $tenRun = $models->get(10);

        $this->assertSame(0.3, round((float) ($oneRun['processing_only_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(2.1, round((float) ($oneRun['effective_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(2.3, round((float) ($oneRun['total_cost_usd'] ?? 0), 1));
        $this->assertSame(-1.3, round((float) ($oneRun['margin_usd'] ?? 0), 1));

        $this->assertSame(3.0, round((float) ($tenRun['processing_only_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(4.8, round((float) ($tenRun['effective_compute_cost_usd'] ?? 0), 1));
        $this->assertSame(6.8, round((float) ($tenRun['total_cost_usd'] ?? 0), 1));
        $this->assertSame(3.2, round((float) ($tenRun['margin_usd'] ?? 0), 1));
    }

    public function test_cost_model_requires_busy_seconds_per_run(): void
    {
        $response = $this->postJson('/api/admin/studio/economics/cost-model', [
            'compute_rate_usd_per_second' => 0.01,
        ]);

        $response->assertStatus(422);
    }

    public function test_cost_model_requires_compute_rate(): void
    {
        $response = $this->postJson('/api/admin/studio/economics/cost-model', [
            'busy_seconds_per_run' => 30,
        ]);

        $response->assertStatus(422);
    }

    public function test_cost_model_omits_margin_when_revenue_not_provided(): void
    {
        $response = $this->postJson('/api/admin/studio/economics/cost-model', [
            'startup_seconds' => 0,
            'busy_seconds_per_run' => 10,
            'idle_seconds_after_batch' => 0,
            'compute_rate_usd_per_second' => 0.01,
            'partner_cost_usd_per_run' => 0,
            'run_counts' => [1],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $models = collect($response->json('data.models'))->keyBy('run_count');
        $oneRun = $models->get(1);
        $this->assertNull($oneRun['revenue_total_usd'] ?? null);
        $this->assertNull($oneRun['margin_usd'] ?? null);
    }

    public function test_cost_model_defaults_run_counts_when_omitted(): void
    {
        $response = $this->postJson('/api/admin/studio/economics/cost-model', [
            'busy_seconds_per_run' => 10,
            'compute_rate_usd_per_second' => 0.01,
        ]);

        $response->assertStatus(200);
        $counts = collect($response->json('data.models'))->pluck('run_count')->all();
        $this->assertSame([1, 10, 100], $counts);
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
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
