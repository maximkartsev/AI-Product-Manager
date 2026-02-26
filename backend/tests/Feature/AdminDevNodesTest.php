<?php

namespace Tests\Feature;

use App\Models\DevNode;
use App\Models\ExecutionEnvironment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDevNodesTest extends TestCase
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

    public function test_can_create_dev_node_and_auto_attach_execution_environment(): void
    {
        $response = $this->postJson('/api/admin/studio/dev-nodes', [
            'name' => 'Debug Node A',
            'instance_type' => 'g5.xlarge',
            'stage' => 'staging',
            'lifecycle' => 'spot',
            'status' => 'starting',
            'metadata_json' => ['source' => 'test'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stage', 'test')
            ->assertJsonPath('data.lifecycle', 'spot')
            ->assertJsonPath('data.execution_environment.kind', 'dev_node')
            ->assertJsonPath('data.execution_environment.stage', 'test');

        $nodeId = (int) $response->json('data.id');
        $this->assertTrue($nodeId > 0);

        $node = DevNode::query()->findOrFail($nodeId);
        $this->assertSame('test', $node->stage);
        $this->assertNotNull($node->started_at);

        $environment = ExecutionEnvironment::query()
            ->where('kind', 'dev_node')
            ->where('dev_node_id', $nodeId)
            ->first();

        $this->assertNotNull($environment);
        $this->assertTrue((bool) $environment->is_active);
    }

    public function test_can_update_dev_node_status_and_refresh_environment_state(): void
    {
        $node = DevNode::query()->create([
            'name' => 'Debug Node B',
            'instance_type' => 'g5.xlarge',
            'stage' => 'dev',
            'lifecycle' => 'on-demand',
            'status' => 'starting',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        ExecutionEnvironment::query()->create([
            'name' => 'Dev Node - Debug Node B',
            'kind' => 'dev_node',
            'stage' => 'dev',
            'dev_node_id' => $node->id,
            'configuration_json' => ['instance_type' => 'g5.xlarge'],
            'is_active' => true,
        ]);

        $response = $this->patchJson("/api/admin/studio/dev-nodes/{$node->id}", [
            'status' => 'ready',
            'public_endpoint' => 'https://devnode.example.internal',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.execution_environment.kind', 'dev_node')
            ->assertJsonPath('data.execution_environment.is_active', true);

        $updated = $node->fresh();
        $this->assertNotNull($updated?->ready_at);

        $environment = ExecutionEnvironment::query()
            ->where('kind', 'dev_node')
            ->where('dev_node_id', $node->id)
            ->first();
        $this->assertNotNull($environment);
        $this->assertTrue((bool) $environment->is_active);
    }

    public function test_rejects_missing_name(): void
    {
        $response = $this->postJson('/api/admin/studio/dev-nodes', [
            'instance_type' => 'g5.xlarge',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_nonexistent_dev_node_returns_404(): void
    {
        $response = $this->patchJson('/api/admin/studio/dev-nodes/999999', [
            'status' => 'ready',
        ]);

        $response->assertStatus(404);
    }

    public function test_stopping_node_marks_environment_inactive(): void
    {
        $node = DevNode::query()->create([
            'name' => 'Debug Node C',
            'instance_type' => 'g5.xlarge',
            'stage' => 'dev',
            'lifecycle' => 'on-demand',
            'status' => 'ready',
            'started_at' => now(),
            'ready_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->patchJson("/api/admin/studio/dev-nodes/{$node->id}", [
            'status' => 'stopped',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.execution_environment.is_active', false);
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
        DB::connection('central')->table('execution_environments')->truncate();
        DB::connection('central')->table('dev_nodes')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
