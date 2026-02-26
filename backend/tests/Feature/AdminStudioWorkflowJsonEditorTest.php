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

class AdminStudioWorkflowJsonEditorTest extends TestCase
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

    public function test_get_workflow_json_returns_decoded_payload(): void
    {
        $path = 'resources/comfyui/workflows/workflow-json-editor-get.json';
        Storage::disk('s3')->put($path, json_encode([
            '1' => ['class_type' => 'PromptNode', 'inputs' => ['text' => 'hello']],
        ]));

        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => $path,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/admin/studio/workflows/{$workflow->id}/json");
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_id', $workflow->id)
            ->assertJsonPath('data.comfyui_workflow_path', $path)
            ->assertJsonPath('data.workflow_json.1.class_type', 'PromptNode');
    }

    public function test_get_workflow_json_returns_422_for_missing_or_invalid_payload(): void
    {
        $missing = Workflow::query()->create([
            'name' => 'Missing JSON Workflow ' . uniqid(),
            'slug' => 'missing-json-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/not-found.json',
            'is_active' => true,
        ]);

        $responseMissing = $this->getJson("/api/admin/studio/workflows/{$missing->id}/json");
        $responseMissing->assertStatus(422)
            ->assertJsonPath('success', false);

        $invalidPath = 'resources/comfyui/workflows/workflow-json-editor-invalid.json';
        Storage::disk('s3')->put($invalidPath, '{invalid-json');
        $invalid = Workflow::query()->create([
            'name' => 'Invalid JSON Workflow ' . uniqid(),
            'slug' => 'invalid-json-' . uniqid(),
            'comfyui_workflow_path' => $invalidPath,
            'is_active' => true,
        ]);

        $responseInvalid = $this->getJson("/api/admin/studio/workflows/{$invalid->id}/json");
        $responseInvalid->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_put_workflow_json_creates_revision_and_updates_path(): void
    {
        $initialPath = 'resources/comfyui/workflows/workflow-json-editor-initial.json';
        Storage::disk('s3')->put($initialPath, json_encode([
            '1' => ['class_type' => 'PromptNode', 'inputs' => ['text' => 'before']],
        ]));

        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => $initialPath,
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/admin/studio/workflows/{$workflow->id}/json", [
            'workflow_json' => [
                '1' => ['class_type' => 'PromptNode', 'inputs' => ['text' => 'after']],
                '2' => ['class_type' => 'SaveImage', 'inputs' => ['images' => ['1', 0]]],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workflow_id', $workflow->id)
            ->assertJsonPath('data.workflow_json.1.inputs.text', 'after');

        $updatedPath = (string) $response->json('data.comfyui_workflow_path');
        $this->assertNotSame($initialPath, $updatedPath);
        $this->assertStringContainsString("workflows/{$workflow->id}/revisions/", $updatedPath);
        Storage::disk('s3')->assertExists($updatedPath);

        $workflow->refresh();
        $this->assertSame($updatedPath, (string) $workflow->comfyui_workflow_path);

        $revision = WorkflowRevision::query()
            ->where('workflow_id', $workflow->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($revision);
        $this->assertSame($updatedPath, (string) $revision->comfyui_workflow_path);
    }

    public function test_put_workflow_json_rejects_invalid_payload(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/test.json',
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/admin/studio/workflows/{$workflow->id}/json", [
            'workflow_json' => 'not-an-object',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
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

