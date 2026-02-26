<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\EffectRevision;
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

class AdminStudioEffectCloneTest extends TestCase
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

    public function test_clone_effect_only_keeps_original_workflow_and_creates_revision(): void
    {
        $workflow = $this->createWorkflowWithJson('effect-only');
        $effect = $this->createSourceEffect($workflow->id);

        $response = $this->postJson("/api/admin/studio/effects/{$effect->id}/clone", [
            'mode' => 'effect_only',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.effect.workflow_id', $workflow->id)
            ->assertJsonPath('data.effect.publication_status', 'development');

        $clonedEffectId = (int) $response->json('data.effect.id');
        $this->assertTrue($clonedEffectId > 0);
        $this->assertNotSame($effect->id, $clonedEffectId);

        $clonedEffect = Effect::query()->findOrFail($clonedEffectId);
        $this->assertSame($workflow->id, (int) $clonedEffect->workflow_id);
        $this->assertNotSame($effect->slug, $clonedEffect->slug);

        $revision = EffectRevision::query()->where('effect_id', $clonedEffectId)->first();
        $this->assertNotNull($revision);
    }

    public function test_clone_effect_and_workflow_creates_new_workflow_and_links_effect_to_it(): void
    {
        $workflow = $this->createWorkflowWithJson('effect-and-workflow');
        $effect = $this->createSourceEffect($workflow->id);

        $response = $this->postJson("/api/admin/studio/effects/{$effect->id}/clone", [
            'mode' => 'effect_and_workflow',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $clonedEffectId = (int) $response->json('data.effect.id');
        $clonedWorkflowId = (int) $response->json('data.workflow.id');

        $this->assertTrue($clonedEffectId > 0);
        $this->assertTrue($clonedWorkflowId > 0);
        $this->assertNotSame($workflow->id, $clonedWorkflowId);

        $clonedEffect = Effect::query()->findOrFail($clonedEffectId);
        $this->assertSame($clonedWorkflowId, (int) $clonedEffect->workflow_id);
        $this->assertSame('development', (string) $clonedEffect->publication_status);

        $workflowRevision = WorkflowRevision::query()->where('workflow_id', $clonedWorkflowId)->first();
        $this->assertNotNull($workflowRevision);
    }

    public function test_clone_effect_rejects_invalid_mode_and_returns_404_for_missing_effect(): void
    {
        $workflow = $this->createWorkflowWithJson('invalid-mode');
        $effect = $this->createSourceEffect($workflow->id);

        $invalidMode = $this->postJson("/api/admin/studio/effects/{$effect->id}/clone", [
            'mode' => 'not_supported',
        ]);
        $invalidMode->assertStatus(422);

        $missing = $this->postJson('/api/admin/studio/effects/999999/clone', [
            'mode' => 'effect_only',
        ]);
        $missing->assertStatus(404);
    }

    private function createWorkflowWithJson(string $suffix): Workflow
    {
        $path = "resources/comfyui/workflows/studio-effect-clone-{$suffix}.json";
        Storage::disk('s3')->put($path, json_encode([
            '1' => ['class_type' => 'PromptNode', 'inputs' => ['text' => "workflow {$suffix}"]],
        ]));

        return Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'description' => 'Clone source workflow',
            'comfyui_workflow_path' => $path,
            'properties' => [['key' => 'style', 'type' => 'text', 'user_configurable' => true]],
            'output_node_id' => '1',
            'output_extension' => 'png',
            'output_mime_type' => 'image/png',
            'is_active' => true,
        ]);
    }

    private function createSourceEffect(int $workflowId): Effect
    {
        return Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Clone source effect',
            'workflow_id' => $workflowId,
            'property_overrides' => ['style' => 'cinematic'],
            'type' => 'video',
            'credits_cost' => 5,
            'popularity_score' => 1,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'publication_status' => 'development',
        ]);
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
        DB::connection('central')->table('effect_revisions')->truncate();
        DB::connection('central')->table('workflow_revisions')->truncate();
        DB::connection('central')->table('effects')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}

