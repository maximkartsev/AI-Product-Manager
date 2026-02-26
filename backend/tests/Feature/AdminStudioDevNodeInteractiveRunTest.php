<?php

namespace Tests\Feature;

use App\Models\DevNode;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\RunArtifact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudioDevNodeInteractiveRunTest extends TestCase
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

    public function test_executes_interactive_devnode_run_and_persists_artifacts(): void
    {
        [$workflow, $revision, $environment] = $this->seedInteractiveRunPrerequisites();

        Storage::disk('s3')->put('inputs/source.mp4', 'binary-source');
        Storage::disk('s3')->put('assets/style.png', 'binary-style');
        Storage::disk('s3')->put($workflow->comfyui_workflow_path, json_encode([
            '1' => [
                'class_type' => 'SomeNode',
                'inputs' => [
                    'video' => '__INPUT_PATH__',
                    'prompt' => '__PROMPT__',
                    'style' => '__STYLE_IMAGE__',
                ],
            ],
            '99' => [
                'class_type' => 'SaveVideo',
                'inputs' => [
                    'video' => ['1', 0],
                ],
            ],
        ]));

        Http::fake([
            'http://devnode.example.com:8188/upload/image' => Http::sequence()
                ->push(['name' => 'uploaded-input.mp4'], 200)
                ->push(['name' => 'uploaded-style.png'], 200),
            'http://devnode.example.com:8188/prompt' => Http::response([
                'prompt_id' => 'prompt-123',
            ], 200),
            'http://devnode.example.com:8188/history/prompt-123' => Http::response([
                'prompt-123' => [
                    'status' => [
                        'status_str' => 'success',
                    ],
                    'outputs' => [
                        '99' => [
                            'videos' => [[
                                'filename' => 'result.mp4',
                                'subfolder' => '',
                                'type' => 'output',
                            ]],
                        ],
                    ],
                ],
            ], 200),
            'http://devnode.example.com:8188/view*' => Http::response('rendered-output', 200, [
                'Content-Type' => 'video/mp4',
            ]),
        ]);

        $response = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
                'input_disk' => 's3',
                'input_name' => 'source.mp4',
                'input_mime_type' => 'video/mp4',
                'properties' => [
                    'prompt' => 'A dragon over mountains',
                    'style_image' => [
                        'disk' => 's3',
                        'path' => 'assets/style.png',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.run.run_mode', 'interactive');

        $runId = (int) $response->json('data.run.id');
        $this->assertTrue($runId > 0);

        $run = EffectTestRun::query()->findOrFail($runId);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);

        $artifacts = RunArtifact::query()
            ->where('effect_test_run_id', $runId)
            ->orderBy('id')
            ->get();
        $this->assertGreaterThanOrEqual(1, $artifacts->count());

        $outputArtifact = $artifacts->firstWhere('artifact_type', 'interactive_output');
        $this->assertNotNull($outputArtifact);
        $this->assertNotNull($outputArtifact->storage_path);
        Storage::disk('s3')->assertExists((string) $outputArtifact->storage_path);
    }

    public function test_validates_required_fields_for_interactive_run_submission(): void
    {
        $response = $this->postJson('/api/admin/studio/devnode-runs', [
            'input_payload' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_rejects_invalid_or_unready_execution_environment_inputs(): void
    {
        [$workflow, $revision] = $this->seedInteractiveRunPrerequisites(includeEnvironment: false);

        $testAsgEnvironment = ExecutionEnvironment::query()->create([
            'name' => 'Test ASG Environment',
            'kind' => 'test_asg',
            'stage' => 'test',
            'is_active' => true,
        ]);

        $notDevNode = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $testAsgEnvironment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
            ],
        ]);
        $notDevNode->assertStatus(422);

        $inactiveNode = DevNode::query()->create([
            'name' => 'Inactive DevNode',
            'stage' => 'dev',
            'status' => 'ready',
            'public_endpoint' => 'http://inactive.example.com:8188',
        ]);
        $inactiveEnvironment = ExecutionEnvironment::query()->create([
            'name' => 'Inactive Environment',
            'kind' => 'dev_node',
            'stage' => 'dev',
            'dev_node_id' => $inactiveNode->id,
            'is_active' => false,
        ]);

        $inactiveResponse = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $inactiveEnvironment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
            ],
        ]);
        $inactiveResponse->assertStatus(422);

        $stoppedNode = DevNode::query()->create([
            'name' => 'Stopped DevNode',
            'stage' => 'dev',
            'status' => 'stopped',
            'public_endpoint' => 'http://stopped.example.com:8188',
        ]);
        $stoppedEnvironment = ExecutionEnvironment::query()->create([
            'name' => 'Stopped Environment',
            'kind' => 'dev_node',
            'stage' => 'dev',
            'dev_node_id' => $stoppedNode->id,
            'is_active' => true,
        ]);

        $stoppedResponse = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $stoppedEnvironment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
            ],
        ]);
        $stoppedResponse->assertStatus(422);

        $missingEndpointNode = DevNode::query()->create([
            'name' => 'No Endpoint DevNode',
            'stage' => 'dev',
            'status' => 'ready',
            'public_endpoint' => null,
            'private_endpoint' => null,
        ]);
        $missingEndpointEnvironment = ExecutionEnvironment::query()->create([
            'name' => 'Missing Endpoint Environment',
            'kind' => 'dev_node',
            'stage' => 'dev',
            'dev_node_id' => $missingEndpointNode->id,
            'is_active' => true,
        ]);

        $missingEndpointResponse = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $missingEndpointEnvironment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
            ],
        ]);
        $missingEndpointResponse->assertStatus(422);
    }

    public function test_rejects_when_workflow_json_is_missing_or_invalid(): void
    {
        [$workflow, $revision, $environment] = $this->seedInteractiveRunPrerequisites();

        $missing = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
            ],
        ]);
        $missing->assertStatus(422);

        Storage::disk('s3')->put($workflow->comfyui_workflow_path, '{invalid-json');
        $invalid = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
            ],
        ]);
        $invalid->assertStatus(422);
    }

    public function test_marks_run_failed_when_devnode_history_returns_error_status(): void
    {
        [$workflow, $revision, $environment] = $this->seedInteractiveRunPrerequisites();

        Storage::disk('s3')->put('inputs/source.mp4', 'binary-source');
        Storage::disk('s3')->put($workflow->comfyui_workflow_path, json_encode([
            '1' => [
                'class_type' => 'SomeNode',
                'inputs' => [
                    'video' => '__INPUT_PATH__',
                    'prompt' => '__PROMPT__',
                ],
            ],
        ]));

        Http::fake([
            'http://devnode.example.com:8188/upload/image' => Http::response([
                'name' => 'uploaded-input.mp4',
            ], 200),
            'http://devnode.example.com:8188/prompt' => Http::response([
                'prompt_id' => 'prompt-err',
            ], 200),
            'http://devnode.example.com:8188/history/prompt-err' => Http::response([
                'prompt-err' => [
                    'status' => [
                        'status_str' => 'error',
                        'message' => 'Comfy node failed.',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/studio/devnode-runs', [
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_payload' => [
                'input_path' => 'inputs/source.mp4',
                'input_disk' => 's3',
                'input_name' => 'source.mp4',
                'input_mime_type' => 'video/mp4',
                'properties' => [
                    'prompt' => 'Trigger an error',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $runId = (int) ($response->json('data.run_id') ?? 0);
        $this->assertTrue($runId > 0);

        $run = EffectTestRun::query()->findOrFail($runId);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->completed_at);

        $errorArtifact = RunArtifact::query()
            ->where('effect_test_run_id', $runId)
            ->where('artifact_type', 'interactive_error')
            ->first();
        $this->assertNotNull($errorArtifact);
        $this->assertStringContainsString(
            'Comfy node failed',
            json_encode($errorArtifact->metadata_json ?? [])
        );
    }

    /**
     * @return array{0: Workflow, 1: EffectRevision, 2?: ExecutionEnvironment}
     */
    private function seedInteractiveRunPrerequisites(bool $includeEnvironment = true): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/devnode-interactive.json',
            'properties' => [
                [
                    'key' => 'input_video',
                    'name' => 'Input Video',
                    'type' => 'video',
                    'required' => true,
                    'placeholder' => '__INPUT_PATH__',
                    'user_configurable' => true,
                    'is_primary_input' => true,
                ],
                [
                    'key' => 'prompt',
                    'name' => 'Prompt',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => '__PROMPT__',
                    'user_configurable' => true,
                ],
                [
                    'key' => 'style_image',
                    'name' => 'Style Image',
                    'type' => 'image',
                    'required' => false,
                    'placeholder' => '__STYLE_IMAGE__',
                    'user_configurable' => true,
                ],
            ],
            'output_node_id' => '99',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'is_active' => true,
        ]);

        $effect = Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Interactive run source effect',
            'workflow_id' => $workflow->id,
            'property_overrides' => ['prompt' => 'Default prompt'],
            'type' => 'video',
            'credits_cost' => 5,
            'popularity_score' => 1,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'publication_status' => 'development',
        ]);

        $revision = EffectRevision::query()->create([
            'effect_id' => $effect->id,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
            'property_overrides' => ['prompt' => 'Revision prompt'],
            'snapshot_json' => ['effect' => ['id' => $effect->id]],
            'created_by_user_id' => $this->adminUser->id,
        ]);

        if (!$includeEnvironment) {
            return [$workflow, $revision];
        }

        $devNode = DevNode::query()->create([
            'name' => 'Dev Node A',
            'stage' => 'dev',
            'status' => 'ready',
            'public_endpoint' => 'http://devnode.example.com:8188',
            'lifecycle' => 'on-demand',
        ]);

        $environment = ExecutionEnvironment::query()->create([
            'name' => 'Dev Node Environment',
            'kind' => 'dev_node',
            'stage' => 'dev',
            'dev_node_id' => $devNode->id,
            'is_active' => true,
        ]);

        return [$workflow, $revision, $environment];
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
        DB::connection('central')->table('run_artifacts')->truncate();
        DB::connection('central')->table('effect_test_runs')->truncate();
        DB::connection('central')->table('execution_environments')->truncate();
        DB::connection('central')->table('dev_nodes')->truncate();
        DB::connection('central')->table('effect_revisions')->truncate();
        DB::connection('central')->table('effects')->truncate();
        DB::connection('central')->table('workflow_revisions')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}

