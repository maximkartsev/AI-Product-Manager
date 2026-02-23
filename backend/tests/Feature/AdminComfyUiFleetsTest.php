<?php

namespace Tests\Feature;

use App\Models\ComfyUiAssetBundle;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Services\ComfyUiFleetSsmService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminComfyUiFleetsTest extends TestCase
{
    protected static bool $prepared = false;

    private User $adminUser;
    private Tenant $tenant;
    private object $fakeSsm;

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

        $this->fakeSsm = new class extends ComfyUiFleetSsmService {
            public array $calls = [];
            public array $desiredCalls = [];

            public function putActiveBundle(string $stage, string $fleetSlug, string $bundlePrefix): void
            {
                $this->calls[] = compact('stage', 'fleetSlug', 'bundlePrefix');
            }

            public function putDesiredFleetConfig(string $stage, string $fleetSlug, array $payload): void
            {
                $this->desiredCalls[] = compact('stage', 'fleetSlug', 'payload');
            }
        };

        app()->instance(ComfyUiFleetSsmService::class, $this->fakeSsm);
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('comfyui_workflow_fleets')->truncate();
        DB::connection('central')->table('comfyui_gpu_fleets')->truncate();
        DB::connection('central')->table('comfyui_asset_bundles')->truncate();
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

    private function adminPost(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->postJson($uri, $data);
    }

    private function adminPatch(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->patchJson($uri, $data);
    }

    private function adminPut(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->putJson($uri, $data);
    }

    public function test_fleets_store_creates_fleet_with_default_ami_param(): void
    {
        $response = $this->adminPost('/api/admin/comfyui-fleets', [
            'stage' => 'staging',
            'slug' => 'gpu-default',
            'name' => 'Default GPU Fleet',
            'template_slug' => 'gpu-default',
            'instance_type' => 'g4dn.xlarge',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $fleet = ComfyUiGpuFleet::query()->first();
        $this->assertNotNull($fleet);
        $this->assertSame('staging', $fleet->stage);
        $this->assertSame('/bp/ami/fleets/staging/gpu-default', $fleet->ami_ssm_parameter);
        $this->assertSame('gpu-default', $fleet->template_slug);
        $this->assertSame(['g4dn.xlarge'], $fleet->instance_types);
        $this->assertSame(10, $fleet->max_size);

        $this->assertCount(1, $this->fakeSsm->desiredCalls);
        $this->assertSame('staging', $this->fakeSsm->desiredCalls[0]['stage']);
        $this->assertSame('gpu-default', $this->fakeSsm->desiredCalls[0]['fleetSlug']);
    }

    public function test_fleets_store_rejects_invalid_instance_type(): void
    {
        $response = $this->adminPost('/api/admin/comfyui-fleets', [
            'stage' => 'staging',
            'slug' => 'gpu-invalid',
            'name' => 'Invalid Fleet',
            'template_slug' => 'gpu-default',
            'instance_type' => 'p3.2xlarge',
        ]);

        $response->assertStatus(422);
    }

    public function test_fleets_update_persists_changes(): void
    {
        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => 'staging',
            'slug' => 'gpu-default',
            'template_slug' => 'gpu-default',
            'name' => 'Default GPU Fleet',
            'instance_types' => ['g4dn.xlarge'],
            'max_size' => 10,
        ]);

        $response = $this->adminPatch("/api/admin/comfyui-fleets/{$fleet->id}", [
            'name' => 'Updated Fleet',
            'instance_type' => 'g5.xlarge',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $fleet->refresh();
        $this->assertSame('Updated Fleet', $fleet->name);
        $this->assertSame(['g5.xlarge'], $fleet->instance_types);
    }

    public function test_fleets_update_rejects_scaling_changes(): void
    {
        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => 'staging',
            'slug' => 'gpu-default',
            'template_slug' => 'gpu-default',
            'name' => 'Default GPU Fleet',
            'instance_types' => ['g4dn.xlarge'],
            'max_size' => 10,
        ]);

        $response = $this->adminPatch("/api/admin/comfyui-fleets/{$fleet->id}", [
            'max_size' => 99,
        ]);

        $response->assertStatus(422);
    }

    public function test_fleets_assign_workflows_creates_mapping(): void
    {
        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => 'staging',
            'slug' => 'gpu-default',
            'name' => 'Default GPU Fleet',
            'max_size' => 5,
        ]);
        $workflow = Workflow::query()->create([
            'name' => 'Workflow A',
            'slug' => 'workflow-a',
            'is_active' => true,
        ]);

        $response = $this->adminPut("/api/admin/comfyui-fleets/{$fleet->id}/workflows", [
            'workflow_ids' => [$workflow->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame(1, ComfyUiWorkflowFleet::query()->count());
        $record = ComfyUiWorkflowFleet::query()->first();
        $this->assertSame($workflow->id, $record->workflow_id);
        $this->assertSame($fleet->id, $record->fleet_id);
        $this->assertSame('staging', $record->stage);
    }

    public function test_fleets_activate_bundle_updates_db_and_calls_ssm(): void
    {
        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => 'staging',
            'slug' => 'gpu-default',
            'template_slug' => 'gpu-default',
            'name' => 'Default GPU Fleet',
            'instance_types' => ['g4dn.xlarge'],
            'max_size' => 10,
        ]);
        $bundle = ComfyUiAssetBundle::query()->create([
            'bundle_id' => (string) Str::uuid(),
            'name' => 'Bundle',
            's3_prefix' => 'bundles/test-bundle',
        ]);

        $response = $this->adminPost("/api/admin/comfyui-fleets/{$fleet->id}/activate-bundle", [
            'bundle_id' => $bundle->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $fleet->refresh();
        $this->assertSame($bundle->id, $fleet->active_bundle_id);
        $this->assertSame('bundles/test-bundle', $fleet->active_bundle_s3_prefix);

        $this->assertCount(1, $this->fakeSsm->calls);
        $this->assertSame('staging', $this->fakeSsm->calls[0]['stage']);
        $this->assertSame('gpu-default', $this->fakeSsm->calls[0]['fleetSlug']);
        $this->assertSame('bundles/test-bundle', $this->fakeSsm->calls[0]['bundlePrefix']);
    }
}
