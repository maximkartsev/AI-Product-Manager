<?php

namespace Tests\Feature;

use App\Models\AiJobDispatch;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudioBlackboxRunnerTest extends TestCase
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

    public function test_blackbox_runner_creates_studio_run_dispatches_and_bills_tokens(): void
    {
        [$workflow, $effect, $revision, $environment] = $this->seedBlackboxPrerequisites(withStagingFleetAssignment: true);
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);
        $this->seedWallet($this->tenant->id, $this->adminUser->id, 50);

        $response = $this->postJson('/api/admin/studio/blackbox-runs', [
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_file_id' => $fileId,
            'input_payload' => [
                'prompt' => 'blackbox smoke prompt',
            ],
            'count' => 1,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.run.run_mode', 'blackbox')
            ->assertJsonPath('data.run.effect_revision_id', $revision->id)
            ->assertJsonPath('data.job_ids.0', 1)
            ->assertJsonPath('data.dispatch_count', 1)
            ->assertJsonPath('data.cost_report.models.0.run_count', 1)
            ->assertJsonPath('data.cost_report.models.1.run_count', 10)
            ->assertJsonPath('data.cost_report.models.2.run_count', 100);

        $runId = (int) $response->json('data.run.id');
        $this->assertTrue($runId > 0);

        $run = EffectTestRun::query()->findOrFail($runId);
        $this->assertSame('blackbox', $run->run_mode);
        $this->assertContains($run->status, ['queued', 'running']);

        $this->assertSame(
            1,
            DB::connection('tenant_pool_1')
                ->table('ai_jobs')
                ->where('tenant_id', $this->tenant->id)
                ->count()
        );
        $this->assertSame(
            1,
            DB::connection('tenant_pool_1')
                ->table('token_transactions')
                ->where('tenant_id', $this->tenant->id)
                ->where('type', 'JOB_RESERVE')
                ->count()
        );
        $this->assertSame(
            44,
            (int) DB::connection('tenant_pool_1')
                ->table('token_wallets')
                ->where('tenant_id', $this->tenant->id)
                ->value('balance')
        );
        $this->assertSame(1, AiJobDispatch::query()->where('stage', 'staging')->count());
    }

    public function test_blackbox_runner_supports_batch_count_and_default_cost_run_counts(): void
    {
        [$workflow, $effect, $revision, $environment] = $this->seedBlackboxPrerequisites(withStagingFleetAssignment: true);
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);
        $this->seedWallet($this->tenant->id, $this->adminUser->id, 100);

        $response = $this->postJson('/api/admin/studio/blackbox-runs', [
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_file_id' => $fileId,
            'count' => 3,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.dispatch_count', 3)
            ->assertJsonPath('data.cost_report.models.0.run_count', 1)
            ->assertJsonPath('data.cost_report.models.1.run_count', 10)
            ->assertJsonPath('data.cost_report.models.2.run_count', 100);

        $this->assertSame(3, AiJobDispatch::query()->where('stage', 'staging')->count());
    }

    public function test_blackbox_runner_validation_requires_core_fields_and_input_reference(): void
    {
        $response = $this->postJson('/api/admin/studio/blackbox-runs', [
            'count' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_blackbox_runner_rejects_non_test_asg_or_inactive_execution_environment(): void
    {
        [$workflow, $effect, $revision] = $this->seedBlackboxPrerequisites(withEnvironment: false, withStagingFleetAssignment: true);
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);
        $this->seedWallet($this->tenant->id, $this->adminUser->id, 20);

        $prodEnvironment = ExecutionEnvironment::query()->create([
            'name' => 'Production ASG',
            'kind' => 'prod_asg',
            'stage' => 'production',
            'fleet_slug' => 'prod-fleet-a',
            'is_active' => true,
        ]);

        $notTestAsg = $this->postJson('/api/admin/studio/blackbox-runs', [
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $prodEnvironment->id,
            'input_file_id' => $fileId,
        ]);
        $notTestAsg->assertStatus(422);

        $inactiveTestAsg = ExecutionEnvironment::query()->create([
            'name' => 'Inactive Test ASG',
            'kind' => 'test_asg',
            'stage' => 'test',
            'fleet_slug' => 'test-fleet-a',
            'is_active' => false,
        ]);

        $inactive = $this->postJson('/api/admin/studio/blackbox-runs', [
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $inactiveTestAsg->id,
            'input_file_id' => $fileId,
        ]);
        $inactive->assertStatus(422);
    }

    public function test_blackbox_runner_rejects_when_staging_fleet_assignment_missing(): void
    {
        [$workflow, $effect, $revision, $environment] = $this->seedBlackboxPrerequisites(withStagingFleetAssignment: false);
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);
        $this->seedWallet($this->tenant->id, $this->adminUser->id, 20);

        $response = $this->postJson('/api/admin/studio/blackbox-runs', [
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_file_id' => $fileId,
        ]);

        $response->assertStatus(422);
    }

    public function test_blackbox_runner_returns_422_when_wallet_tokens_insufficient_and_no_dispatch_created(): void
    {
        [$workflow, $effect, $revision, $environment] = $this->seedBlackboxPrerequisites(withStagingFleetAssignment: true);
        $fileId = $this->createTenantFile($this->tenant->id, $this->adminUser->id);
        $this->seedWallet($this->tenant->id, $this->adminUser->id, 2);

        $response = $this->postJson('/api/admin/studio/blackbox-runs', [
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_file_id' => $fileId,
            'count' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, AiJobDispatch::query()->count());
    }

    /**
     * @return array{0: Workflow, 1: Effect, 2: EffectRevision, 3?: ExecutionEnvironment}
     */
    private function seedBlackboxPrerequisites(
        bool $withEnvironment = true,
        bool $withStagingFleetAssignment = true
    ): array {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/studio-blackbox.json',
            'properties' => [
                [
                    'key' => 'input_video',
                    'name' => 'Input Video',
                    'type' => 'video',
                    'required' => true,
                    'placeholder' => '__INPUT_PATH__',
                    'is_primary_input' => true,
                ],
                [
                    'key' => 'prompt',
                    'name' => 'Prompt',
                    'type' => 'text',
                    'required' => false,
                    'placeholder' => '__PROMPT__',
                    'user_configurable' => true,
                    'default_value' => 'Default prompt',
                ],
            ],
            'output_node_id' => '99',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'is_active' => true,
        ]);

        Storage::disk('s3')->put($workflow->comfyui_workflow_path, json_encode([
            '1' => [
                'class_type' => 'PromptNode',
                'inputs' => [
                    'video' => '__INPUT_PATH__',
                    'prompt' => '__PROMPT__',
                ],
            ],
        ]));

        $effect = Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Blackbox runner effect',
            'type' => 'video',
            'credits_cost' => 5.2,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
            'property_overrides' => ['prompt' => 'From effect override'],
        ]);

        $revision = EffectRevision::query()->create([
            'effect_id' => $effect->id,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
            'property_overrides' => ['prompt' => 'From revision override'],
            'snapshot_json' => ['effect' => ['id' => $effect->id]],
            'created_by_user_id' => $this->adminUser->id,
        ]);

        if ($withStagingFleetAssignment) {
            $fleet = ComfyUiGpuFleet::query()->create([
                'stage' => 'staging',
                'slug' => 'staging-fleet-' . uniqid(),
                'name' => 'Staging Fleet',
                'instance_types' => ['g4dn.xlarge'],
                'max_size' => 1,
            ]);

            ComfyUiWorkflowFleet::query()->create([
                'workflow_id' => $workflow->id,
                'fleet_id' => $fleet->id,
                'stage' => 'staging',
                'assigned_at' => now(),
                'assigned_by_user_id' => $this->adminUser->id,
                'assigned_by_email' => $this->adminUser->email,
            ]);
        }

        if (!$withEnvironment) {
            return [$workflow, $effect, $revision];
        }

        $environment = ExecutionEnvironment::query()->create([
            'name' => 'Test ASG Environment',
            'kind' => 'test_asg',
            'stage' => 'test',
            'fleet_slug' => 'test-fleet-a',
            'is_active' => true,
        ]);

        return [$workflow, $effect, $revision, $environment];
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

    private function createTenantFile(string $tenantId, int $userId): int
    {
        return (int) DB::connection('tenant_pool_1')->table('files')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'disk' => 's3',
            'path' => 'uploads/' . uniqid() . '.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1234,
            'original_filename' => 'input.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedWallet(string $tenantId, int $userId, int $balance): void
    {
        DB::connection('tenant_pool_1')->table('token_wallets')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'balance' => $balance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('ai_job_dispatches')->truncate();
        DB::connection('central')->table('run_artifacts')->truncate();
        DB::connection('central')->table('effect_test_runs')->truncate();
        DB::connection('central')->table('execution_environments')->truncate();
        DB::connection('central')->table('comfyui_workflow_fleets')->truncate();
        DB::connection('central')->table('comfyui_gpu_fleets')->truncate();
        DB::connection('central')->table('effect_revisions')->truncate();
        DB::connection('central')->table('workflow_revisions')->truncate();
        DB::connection('central')->table('effects')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');

        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('tenant_pool_1')->table('token_transactions')->truncate();
        DB::connection('tenant_pool_1')->table('token_wallets')->truncate();
        DB::connection('tenant_pool_1')->table('ai_jobs')->truncate();
        DB::connection('tenant_pool_1')->table('videos')->truncate();
        DB::connection('tenant_pool_1')->table('files')->truncate();
        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}

