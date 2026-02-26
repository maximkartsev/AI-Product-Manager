<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRevision;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudioWorkflowCloneTest extends TestCase
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

    public function test_clone_workflow_creates_new_workflow_with_copied_json_and_revision(): void
    {
        $sourcePath = 'resources/comfyui/workflows/studio-clone-source.json';
        $sourceJson = [
            '1' => ['class_type' => 'PromptNode', 'inputs' => ['text' => 'clone me']],
        ];
        Storage::disk('s3')->put($sourcePath, json_encode($sourceJson));

        $workflow = Workflow::query()->create([
            'name' => 'Source Workflow',
            'slug' => 'source-workflow-' . uniqid(),
            'description' => 'Original workflow',
            'comfyui_workflow_path' => $sourcePath,
            'properties' => [['key' => 'style', 'type' => 'text', 'user_configurable' => true]],
            'output_node_id' => '1',
            'output_extension' => 'png',
            'output_mime_type' => 'image/png',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/admin/studio/workflows/{$workflow->id}/clone", []);
        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $clonedWorkflowId = (int) $response->json('data.workflow.id');
        $this->assertTrue($clonedWorkflowId > 0);
        $this->assertNotSame($workflow->id, $clonedWorkflowId);

        $clonedPath = (string) $response->json('data.workflow.comfyui_workflow_path');
        $this->assertNotSame($sourcePath, $clonedPath);
        Storage::disk('s3')->assertExists($clonedPath);

        $storedJson = json_decode(Storage::disk('s3')->get($clonedPath) ?: '', true);
        $this->assertSame($sourceJson, $storedJson);

        $clonedWorkflow = Workflow::query()->findOrFail($clonedWorkflowId);
        $this->assertNotSame($workflow->slug, $clonedWorkflow->slug);

        $revision = WorkflowRevision::query()->where('workflow_id', $clonedWorkflowId)->first();
        $this->assertNotNull($revision);
    }

    public function test_clone_workflow_returns_404_for_missing_workflow(): void
    {
        $response = $this->postJson('/api/admin/studio/workflows/999999/clone', []);
        $response->assertStatus(404);
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

