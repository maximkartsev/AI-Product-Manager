<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudioWorkflowRevisionsTest extends TestCase
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
        config(['services.comfyui.workflow_disk' => 's3']);
        Storage::fake('s3');

        $this->resetState();
        [$this->adminUser, $this->tenant] = $this->createAdminUserTenant();
        Sanctum::actingAs($this->adminUser);
    }

    public function test_workflow_revisions_list_empty_then_create_then_list(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'description' => 'A test workflow',
            'comfyui_workflow_path' => 'resources/comfyui/workflows/test.json',
            'is_active' => true,
        ]);

        $indexEmpty = $this->getJson("/api/admin/studio/workflows/{$workflow->id}/revisions");
        $indexEmpty->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data.items');

        $create = $this->postJson("/api/admin/studio/workflows/{$workflow->id}/revisions", []);
        $create->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_id', $workflow->id);

        $revisionId = (int) $create->json('data.id');
        $this->assertTrue($revisionId > 0);

        $index = $this->getJson("/api/admin/studio/workflows/{$workflow->id}/revisions");
        $index->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.id', $revisionId)
            ->assertJsonPath('data.items.0.workflow_id', $workflow->id);
    }

    public function test_workflow_revisions_returns_404_for_missing_workflow(): void
    {
        $index = $this->getJson('/api/admin/studio/workflows/999999/revisions');
        $index->assertStatus(404);

        $create = $this->postJson('/api/admin/studio/workflows/999999/revisions', []);
        $create->assertStatus(404);
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
        DB::connection('central')->table('workflow_revisions')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}

