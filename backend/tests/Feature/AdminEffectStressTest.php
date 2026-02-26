<?php

namespace Tests\Feature;

use App\Models\AiJobDispatch;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEffectStressTest extends TestCase
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
        DB::connection('central')->table('ai_job_dispatches')->truncate();
        DB::connection('central')->table('comfyui_workflow_fleets')->truncate();
        DB::connection('central')->table('comfyui_gpu_fleets')->truncate();
        DB::connection('central')->table('effects')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');

        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('tenant_pool_1')->table('ai_jobs')->truncate();
        DB::connection('tenant_pool_1')->table('files')->truncate();
        DB::connection('tenant_pool_1')->table('videos')->truncate();
        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_stress_test_defaults_to_staging_for_development_effect(): void
    {
        $this->actAsAdmin();
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow->id, ['publication_status' => 'development']);
        $this->createFleetAssignment($workflow->id, 'staging');
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);

        $response = $this->postJson("/api/admin/effects/{$effect->id}/stress-test", [
            'count' => 2,
            'input_file_id' => $fileId,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.queued_count', 2);

        $this->assertSame(2, AiJobDispatch::query()->where('stage', 'staging')->count());
    }

    public function test_stress_test_allows_production_override_for_development_effect(): void
    {
        $this->actAsAdmin();
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow->id, ['publication_status' => 'development']);
        $this->createFleetAssignment($workflow->id, 'production');
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);

        $response = $this->postJson("/api/admin/effects/{$effect->id}/stress-test", [
            'count' => 1,
            'input_file_id' => $fileId,
            'execute_on_production_fleet' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.queued_count', 1);

        $this->assertSame(1, AiJobDispatch::query()->where('stage', 'production')->count());
    }

    public function test_stress_test_ignores_override_for_published_effect(): void
    {
        $this->actAsAdmin();
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow->id, ['publication_status' => 'published']);
        $this->createFleetAssignment($workflow->id, 'production');
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);

        $response = $this->postJson("/api/admin/effects/{$effect->id}/stress-test", [
            'count' => 1,
            'input_file_id' => $fileId,
            'execute_on_production_fleet' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.queued_count', 1);

        $this->assertSame(1, AiJobDispatch::query()->where('stage', 'production')->count());
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

    private function createWorkflow(): Workflow
    {
        $uid = uniqid();
        return Workflow::query()->create([
            'name' => 'Workflow ' . $uid,
            'slug' => 'workflow-' . $uid,
            'is_active' => true,
        ]);
    }

    private function createEffect(int $workflowId, array $overrides = []): Effect
    {
        $uid = uniqid();
        $defaults = [
            'name' => 'Effect ' . $uid,
            'slug' => 'effect-' . $uid,
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflowId,
            'publication_status' => 'published',
        ];

        return Effect::query()->create(array_merge($defaults, $overrides));
    }

    private function createFleetAssignment(int $workflowId, string $stage): void
    {
        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => $stage,
            'slug' => $stage . '-fleet-' . uniqid(),
            'name' => ucfirst($stage) . ' Fleet',
            'instance_types' => ['g4dn.xlarge'],
            'max_size' => 1,
        ]);

        ComfyUiWorkflowFleet::query()->create([
            'workflow_id' => $workflowId,
            'fleet_id' => $fleet->id,
            'stage' => $stage,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->adminUser->id,
            'assigned_by_email' => $this->adminUser->email,
        ]);
    }

    private function createTenantFile(string $tenantId, int $userId): int
    {
        return (int) DB::connection('tenant_pool_1')->table('files')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'disk' => 'local',
            'path' => 'uploads/' . uniqid() . '.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1234,
            'original_filename' => 'input.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
