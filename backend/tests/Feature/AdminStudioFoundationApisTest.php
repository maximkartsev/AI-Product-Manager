<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\ExperimentVariant;
use App\Models\LoadTestRun;
use App\Models\LoadTestScenario;
use App\Models\Tenant;
use App\Models\TestInputSet;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudioFoundationApisTest extends TestCase
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

    public function test_execution_environments_support_filters_and_show(): void
    {
        $activeProd = ExecutionEnvironment::query()->create([
            'name' => 'Prod Env',
            'kind' => 'prod_asg',
            'stage' => 'production',
            'is_active' => true,
        ]);
        ExecutionEnvironment::query()->create([
            'name' => 'Dev Env',
            'kind' => 'dev_node',
            'stage' => 'test',
            'is_active' => false,
        ]);

        $index = $this->getJson('/api/admin/studio/execution-environments?kind=prod_asg&is_active=1');
        $index->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $activeProd->id);

        $show = $this->getJson("/api/admin/studio/execution-environments/{$activeProd->id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $activeProd->id);

        $notFound = $this->getJson('/api/admin/studio/execution-environments/999999');
        $notFound->assertStatus(404);
    }

    public function test_test_input_sets_create_list_show_and_validation(): void
    {
        $invalid = $this->postJson('/api/admin/studio/test-input-sets', [
            'name' => 'Missing payload',
        ]);
        $invalid->assertStatus(422);

        $create = $this->postJson('/api/admin/studio/test-input-sets', [
            'name' => 'Golden Inputs',
            'description' => 'Seed test inputs',
            'input_json' => [
                ['file_id' => 1, 'prompt' => 'one'],
            ],
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Golden Inputs');

        $id = (int) $create->json('data.id');
        $this->assertTrue($id > 0);

        $index = $this->getJson('/api/admin/studio/test-input-sets');
        $index->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $id);

        $show = $this->getJson("/api/admin/studio/test-input-sets/{$id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $id);

        $notFound = $this->getJson('/api/admin/studio/test-input-sets/999999');
        $notFound->assertStatus(404);
    }

    public function test_effect_test_runs_create_show_and_validation(): void
    {
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $inputSet = TestInputSet::query()->create([
            'name' => 'Inputs',
            'input_json' => [['seed' => 1]],
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $invalid = $this->postJson('/api/admin/studio/effect-test-runs', [
            'run_mode' => 'interactive',
            'target_count' => 10,
        ]);
        $invalid->assertStatus(422);

        $create = $this->postJson('/api/admin/studio/effect-test-runs', [
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'test_input_set_id' => $inputSet->id,
            'run_mode' => 'interactive',
            'target_count' => 25,
            'metrics_json' => ['queue_wait_p95' => 3.2],
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.effect_revision_id', $revision->id)
            ->assertJsonPath('data.status', 'queued');

        $id = (int) $create->json('data.id');
        $show = $this->getJson("/api/admin/studio/effect-test-runs/{$id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }

    public function test_load_test_runs_create_show_and_validation(): void
    {
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $scenario = LoadTestScenario::query()->create([
            'name' => 'Scenario A',
            'description' => 'Smoke scenario',
            'is_active' => true,
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $invalid = $this->postJson('/api/admin/studio/load-test-runs', [
            'execution_environment_id' => $environment->id,
        ]);
        $invalid->assertStatus(422);

        $create = $this->postJson('/api/admin/studio/load-test-runs', [
            'load_test_scenario_id' => $scenario->id,
            'execution_environment_id' => $environment->id,
            'effect_revision_id' => $revision->id,
            'status' => 'running',
            'achieved_rpm' => 45.5,
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.load_test_scenario_id', $scenario->id);

        $id = (int) $create->json('data.id');
        $show = $this->getJson("/api/admin/studio/load-test-runs/{$id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $id);

        $notFound = $this->getJson('/api/admin/studio/load-test-runs/999999');
        $notFound->assertStatus(404);
    }

    public function test_experiment_variants_create_update_filters_and_not_found(): void
    {
        $invalid = $this->postJson('/api/admin/studio/experiment-variants', [
            'description' => 'missing name',
        ]);
        $invalid->assertStatus(422);

        $create = $this->postJson('/api/admin/studio/experiment-variants', [
            'name' => 'Spot Bias Variant',
            'description' => 'Prefer spot nodes',
            'target_environment_kind' => 'test_asg',
            'fleet_config_intent_json' => ['spot_ratio' => 0.7],
            'is_active' => false,
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('data.is_active', false);

        $id = (int) $create->json('data.id');
        $update = $this->patchJson("/api/admin/studio/experiment-variants/{$id}", [
            'is_active' => true,
        ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        $index = $this->getJson('/api/admin/studio/experiment-variants?is_active=1');
        $index->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $id);

        $notFound = $this->getJson('/api/admin/studio/experiment-variants/999999');
        $notFound->assertStatus(404);
    }

    public function test_fleet_config_snapshots_create_show_and_validation(): void
    {
        $environment = $this->createExecutionEnvironment();
        $variant = ExperimentVariant::query()->create([
            'name' => 'Variant A',
            'target_environment_kind' => 'test_asg',
            'is_active' => true,
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $invalid = $this->postJson('/api/admin/studio/fleet-config-snapshots', [
            'snapshot_scope' => 'manual',
        ]);
        $invalid->assertStatus(422);

        $create = $this->postJson('/api/admin/studio/fleet-config-snapshots', [
            'execution_environment_id' => $environment->id,
            'experiment_variant_id' => $variant->id,
            'snapshot_scope' => 'manual',
            'config_json' => ['min' => 0, 'max' => 4],
            'composition_json' => ['spot' => 3, 'on_demand' => 1],
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('data.execution_environment_id', $environment->id);

        $id = (int) $create->json('data.id');
        $show = $this->getJson("/api/admin/studio/fleet-config-snapshots/{$id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $id);

        $notFound = $this->getJson('/api/admin/studio/fleet-config-snapshots/999999');
        $notFound->assertStatus(404);
    }

    public function test_production_fleet_snapshots_create_show_and_validation(): void
    {
        $environment = $this->createExecutionEnvironment();

        $invalid = $this->postJson('/api/admin/studio/production-fleet-snapshots', [
            'fleet_slug' => 'prod-fleet-a',
        ]);
        $invalid->assertStatus(422);

        $create = $this->postJson('/api/admin/studio/production-fleet-snapshots', [
            'execution_environment_id' => $environment->id,
            'fleet_slug' => 'prod-fleet-a',
            'queue_depth' => 12,
            'p95_queue_wait_seconds' => 2.25,
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('data.execution_environment_id', $environment->id)
            ->assertJsonPath('data.stage', 'production');

        $id = (int) $create->json('data.id');
        $show = $this->getJson("/api/admin/studio/production-fleet-snapshots/{$id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }

    public function test_run_artifacts_create_show_and_validation(): void
    {
        $effectTestRun = EffectTestRun::query()->create([
            'run_mode' => 'interactive',
            'target_count' => 1,
            'status' => 'completed',
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $loadTestRun = LoadTestRun::query()->create([
            'status' => 'completed',
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $invalid = $this->postJson('/api/admin/studio/run-artifacts', [
            'artifact_type' => 'report',
            'storage_disk' => 's3',
            'storage_path' => 'artifacts/missing-run.json',
        ]);
        $invalid->assertStatus(422);

        $create = $this->postJson('/api/admin/studio/run-artifacts', [
            'effect_test_run_id' => $effectTestRun->id,
            'load_test_run_id' => $loadTestRun->id,
            'artifact_type' => 'report',
            'storage_disk' => 's3',
            'storage_path' => 'artifacts/run-report.json',
            'metadata_json' => ['sha256' => 'abc123'],
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('data.effect_test_run_id', $effectTestRun->id)
            ->assertJsonPath('data.load_test_run_id', $loadTestRun->id);

        $id = (int) $create->json('data.id');
        $show = $this->getJson("/api/admin/studio/run-artifacts/{$id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $id);

        $notFound = $this->getJson('/api/admin/studio/run-artifacts/999999');
        $notFound->assertStatus(404);
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

    private function createExecutionEnvironment(): ExecutionEnvironment
    {
        return ExecutionEnvironment::query()->create([
            'name' => 'Env ' . uniqid(),
            'kind' => 'test_asg',
            'stage' => 'test',
            'is_active' => true,
            'configuration_json' => ['instance_type' => 'g5.xlarge'],
        ]);
    }

    private function createWorkflow(): Workflow
    {
        return Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/cloud_video_effect.json',
            'output_node_id' => '1',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'is_active' => true,
        ]);
    }

    private function createEffect(Workflow $workflow): Effect
    {
        return Effect::query()->create([
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
    }

    private function createRevision(Effect $effect, Workflow $workflow): EffectRevision
    {
        return EffectRevision::query()->create([
            'effect_id' => $effect->id,
            'workflow_id' => $workflow->id,
            'category_id' => $effect->category_id,
            'publication_status' => 'development',
            'property_overrides' => [],
            'snapshot_json' => ['effect' => ['id' => $effect->id]],
            'created_by_user_id' => $this->adminUser->id,
        ]);
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('run_artifacts')->truncate();
        DB::connection('central')->table('production_fleet_snapshots')->truncate();
        DB::connection('central')->table('load_test_runs')->truncate();
        DB::connection('central')->table('fleet_config_snapshots')->truncate();
        DB::connection('central')->table('experiment_variants')->truncate();
        DB::connection('central')->table('load_test_stages')->truncate();
        DB::connection('central')->table('load_test_scenarios')->truncate();
        DB::connection('central')->table('effect_test_runs')->truncate();
        DB::connection('central')->table('test_input_sets')->truncate();
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
}
