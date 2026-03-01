<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\ExecutionEnvironment;
use App\Models\File;
use App\Models\LoadTestRun;
use App\Models\LoadTestScenario;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

abstract class StudioLoadTestFeatureTestCase extends TestCase
{
    protected static bool $prepared = false;

    protected User $adminUser;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabases();

        config(['app.url' => 'http://test.example.com']);
        url()->forceRootUrl('http://test.example.com');
        config(['services.comfyui.studio_executor_secret' => 'test-secret']);
        config(['services.comfyui.workflow_disk' => 'local']);
        $this->ensureWorkflowFixture();

        $this->resetState();
        [$this->adminUser, $this->tenant] = $this->createAdminUserTenant();
    }

    protected function authenticateAdmin(): void
    {
        Sanctum::actingAs($this->adminUser);
    }

    protected function createWorkflow(array $overrides = []): Workflow
    {
        $uid = uniqid();

        return Workflow::query()->create(array_merge([
            'name' => 'Workflow ' . $uid,
            'slug' => 'workflow-' . $uid,
            'comfyui_workflow_path' => 'resources/comfyui/workflows/cloud_video_effect.json',
            'output_node_id' => '1',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'is_active' => true,
            'properties' => [],
        ], $overrides));
    }

    protected function createEffect(Workflow $workflow, array $overrides = []): Effect
    {
        $uid = uniqid();

        return Effect::query()->create(array_merge([
            'name' => 'Effect ' . $uid,
            'slug' => 'effect-' . $uid,
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
        ], $overrides));
    }

    protected function createRevision(Effect $effect, Workflow $workflow, array $overrides = []): EffectRevision
    {
        return EffectRevision::query()->create(array_merge([
            'effect_id' => $effect->id,
            'workflow_id' => $workflow->id,
            'category_id' => $effect->category_id,
            'publication_status' => 'development',
            'property_overrides' => [],
            'snapshot_json' => ['effect' => ['id' => $effect->id]],
            'created_by_user_id' => $this->adminUser->id,
        ], $overrides));
    }

    protected function createExecutionEnvironment(array $overrides = []): ExecutionEnvironment
    {
        return ExecutionEnvironment::query()->create(array_merge([
            'name' => 'Test ASG ' . uniqid(),
            'kind' => 'test_asg',
            'stage' => 'test',
            'is_active' => true,
            'fleet_slug' => 'test-asg-fleet',
            'configuration_json' => [
                'asg_name' => 'test-asg-fleet',
                'fis_experiment_template_id' => 'exp-template-123',
            ],
        ], $overrides));
    }

    protected function createScenario(array $overrides = []): LoadTestScenario
    {
        return LoadTestScenario::query()->create(array_merge([
            'name' => 'Scenario ' . uniqid(),
            'description' => 'Scenario description',
            'is_active' => true,
            'created_by_user_id' => $this->adminUser->id,
        ], $overrides));
    }

    protected function createRun(
        LoadTestScenario $scenario,
        ExecutionEnvironment $environment,
        EffectRevision $revision,
        array $overrides = []
    ): LoadTestRun {
        return LoadTestRun::query()->create(array_merge([
            'load_test_scenario_id' => $scenario->id,
            'execution_environment_id' => $environment->id,
            'effect_revision_id' => $revision->id,
            'status' => 'queued',
            'metrics_json' => [],
            'created_by_user_id' => $this->adminUser->id,
        ], $overrides));
    }

    protected function createTenantFile(User $user, array $overrides = []): File
    {
        /** @var Tenancy $tenancy */
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($this->tenant);

        try {
            // Tenancy suffixes local storage roots; seed the same workflow fixture
            // inside tenant-scoped storage so payload resolution can read it.
            Storage::disk((string) config('services.comfyui.workflow_disk', 'local'))->put(
                'resources/comfyui/workflows/cloud_video_effect.json',
                json_encode([
                    '1' => [
                        'inputs' => ['text' => '__PROMPT__'],
                        'class_type' => 'KSampler',
                    ],
                ], JSON_PRETTY_PRINT)
            );

            return File::query()->create(array_merge([
                'tenant_id' => (string) $this->tenant->id,
                'user_id' => $user->id,
                'disk' => 's3',
                'path' => 'tests/input-' . uniqid() . '.mp4',
                'url' => 'https://example.com/input.mp4',
                'mime_type' => 'video/mp4',
                'size' => 1024,
                'original_filename' => 'input.mp4',
                'metadata' => [],
            ], $overrides));
        } finally {
            $tenancy->end();
        }
    }

    private function prepareDatabases(): void
    {
        if (static::$prepared) {
            return;
        }

        try {
            DB::connection('central')->statement(
                'CREATE DATABASE IF NOT EXISTS tenant_pool_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
            );
            DB::connection('central')->statement(
                'CREATE DATABASE IF NOT EXISTS tenant_pool_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
            );
        } catch (\Throwable) {
            // ignored in local sqlite/in-memory style runs
        }

        Artisan::call('migrate');
        Artisan::call('tenancy:pools-migrate');
        static::$prepared = true;
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

        $centralTables = [
            'ai_job_dispatches',
            'load_test_runs',
            'load_test_stages',
            'load_test_scenarios',
            'execution_environments',
            'effect_revisions',
            'workflow_revisions',
            'effects',
            'workflows',
            'users',
            'tenants',
            'personal_access_tokens',
        ];

        foreach ($centralTables as $table) {
            if (Schema::connection('central')->hasTable($table)) {
                DB::connection('central')->table($table)->truncate();
            }
        }

        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');

        $tenantConnections = ['tenant_pool_1', 'tenant_pool_2'];
        $tenantTables = ['ai_jobs', 'files', 'videos', 'token_transactions', 'token_wallets'];
        foreach ($tenantConnections as $connection) {
            DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tenantTables as $table) {
                if (Schema::connection($connection)->hasTable($table)) {
                    DB::connection($connection)->table($table)->truncate();
                }
            }
            DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function ensureWorkflowFixture(): void
    {
        Storage::disk('local')->put(
            'resources/comfyui/workflows/cloud_video_effect.json',
            json_encode([
                '1' => [
                    'inputs' => [
                        'text' => '__PROMPT__',
                    ],
                    'class_type' => 'KSampler',
                ],
            ], JSON_PRETTY_PRINT)
        );
    }
}
