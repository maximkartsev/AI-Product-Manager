<?php

namespace Tests\Feature;

use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\ExecutionEnvironment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEffectPublishTest extends TestCase
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

    public function test_publish_endpoint_pins_revision_and_environment(): void
    {
        Sanctum::actingAs($this->adminUser);

        [$workflow, $environment] = $this->createWorkflowAndEnvironment();

        $effect = Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
        ]);

        $revision = EffectRevision::query()->create([
            'effect_id' => $effect->id,
            'workflow_id' => $workflow->id,
            'category_id' => $effect->category_id,
            'publication_status' => 'development',
            'property_overrides' => [],
            'snapshot_json' => ['effect' => ['id' => $effect->id]],
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $response = $this->postJson("/api/admin/effects/{$effect->id}/publish", [
            'revision_id' => $revision->id,
            'prod_execution_environment_id' => $environment->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.publication_status', 'published')
            ->assertJsonPath('data.published_revision_id', $revision->id)
            ->assertJsonPath('data.prod_execution_environment_id', $environment->id);
    }

    public function test_update_of_published_effect_keeps_pinned_revision_until_republish(): void
    {
        Sanctum::actingAs($this->adminUser);

        [$workflow, $environment] = $this->createWorkflowAndEnvironment();

        $effect = Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
        ]);

        $revisionA = EffectRevision::query()->create([
            'effect_id' => $effect->id,
            'workflow_id' => $workflow->id,
            'category_id' => $effect->category_id,
            'publication_status' => 'development',
            'property_overrides' => [],
            'snapshot_json' => ['effect' => ['id' => $effect->id]],
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $publishA = $this->postJson("/api/admin/effects/{$effect->id}/publish", [
            'revision_id' => $revisionA->id,
            'prod_execution_environment_id' => $environment->id,
        ]);
        $publishA->assertStatus(200)
            ->assertJsonPath('data.published_revision_id', $revisionA->id);

        $update = $this->patchJson("/api/admin/effects/{$effect->id}", [
            'name' => 'Updated ' . uniqid(),
        ]);
        $update->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.publication_status', 'published')
            ->assertJsonPath('data.published_revision_id', $revisionA->id);

        $effect->refresh();
        $latestRevision = EffectRevision::query()
            ->where('effect_id', $effect->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertNotSame($revisionA->id, $latestRevision->id);
        $this->assertSame($revisionA->id, (int) $effect->published_revision_id);

        $publishB = $this->postJson("/api/admin/effects/{$effect->id}/publish", [
            'revision_id' => $latestRevision->id,
            'prod_execution_environment_id' => $environment->id,
        ]);
        $publishB->assertStatus(200)
            ->assertJsonPath('data.published_revision_id', $latestRevision->id);
    }

    public function test_create_and_update_reject_inline_publish_semantics(): void
    {
        Sanctum::actingAs($this->adminUser);

        [$workflow, $environment] = $this->createWorkflowAndEnvironment();

        $create = $this->postJson('/api/admin/effects', [
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'popularity_score' => 1,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
            'publication_status' => 'published',
            'prod_execution_environment_id' => $environment->id,
        ]);
        $create->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Publishing during create/update is not allowed. Save as development, then call publish endpoint with revision_id and prod_execution_environment_id.'
            );

        $effect = Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
        ]);

        $update = $this->patchJson("/api/admin/effects/{$effect->id}", [
            'publication_status' => 'published',
            'prod_execution_environment_id' => $environment->id,
        ]);
        $update->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Publishing during create/update is not allowed. Save as development, then call publish endpoint with revision_id and prod_execution_environment_id.'
            );
    }

    public function test_effect_revision_endpoints_create_and_list_revisions(): void
    {
        Sanctum::actingAs($this->adminUser);

        [$workflow] = $this->createWorkflowAndEnvironment();

        $effect = Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
        ]);

        $createRevision = $this->postJson("/api/admin/effects/{$effect->id}/revisions");
        $createRevision->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.effect_id', $effect->id);

        $revisionId = (int) $createRevision->json('data.id');
        $this->assertTrue($revisionId > 0);

        $list = $this->getJson("/api/admin/effects/{$effect->id}/revisions");
        $list->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.id', $revisionId)
            ->assertJsonPath('data.items.0.effect_id', $effect->id);
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
        DB::connection('central')->table('ai_job_dispatches')->truncate();
        DB::connection('central')->table('comfyui_workflow_fleets')->truncate();
        DB::connection('central')->table('comfyui_gpu_fleets')->truncate();
        DB::connection('central')->table('execution_environments')->truncate();
        DB::connection('central')->table('effect_revisions')->truncate();
        DB::connection('central')->table('workflow_revisions')->truncate();
        DB::connection('central')->table('effects')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createWorkflowAndEnvironment(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/cloud_video_effect.json',
            'output_node_id' => '1',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'is_active' => true,
        ]);

        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => 'production',
            'slug' => 'prod-fleet-' . uniqid(),
            'name' => 'Production Fleet',
            'instance_types' => ['g4dn.xlarge'],
            'max_size' => 1,
        ]);
        ComfyUiWorkflowFleet::query()->create([
            'workflow_id' => $workflow->id,
            'fleet_id' => $fleet->id,
            'stage' => 'production',
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->adminUser->id,
            'assigned_by_email' => $this->adminUser->email,
        ]);

        $environment = ExecutionEnvironment::query()->create([
            'name' => 'Production ASG - ' . $fleet->slug,
            'kind' => 'prod_asg',
            'stage' => 'production',
            'fleet_slug' => $fleet->slug,
            'configuration_json' => ['instance_types' => ['g4dn.xlarge']],
            'is_active' => true,
        ]);

        return [$workflow, $environment];
    }
}
