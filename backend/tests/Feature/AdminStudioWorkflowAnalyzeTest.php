<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAnalysisJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AdminStudioWorkflowAnalyzeTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_requires_workflow_id_or_workflow_json(): void
    {
        $response = $this->postJson('/api/admin/studio/workflow-analyze', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Either workflow_id or workflow_json is required.');
    }

    public function test_returns_422_when_workflow_storage_payload_is_invalid(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/invalid-analysis.json',
            'output_node_id' => '1',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'is_active' => true,
        ]);

        Storage::disk('s3')->put($workflow->comfyui_workflow_path, '{invalid-json');

        $response = $this->postJson('/api/admin/studio/workflow-analyze', [
            'workflow_id' => $workflow->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Workflow JSON is invalid or empty.');
    }

    public function test_successfully_analyzes_workflow_with_mocked_openai_wrapper(): void
    {
        Mockery::mock('alias:App\Models\OpenAI')
            ->shouldReceive('askChatGPT')
            ->once()
            ->andReturn([
                'properties' => [
                    [
                        'key' => 'style',
                        'name' => 'Style',
                        'type' => 'text',
                        'required' => false,
                        'placeholder' => '__STYLE__',
                        'user_configurable' => true,
                    ],
                ],
                'primary_input' => [
                    'node_id' => '11',
                    'key' => 'image',
                    'type' => 'image',
                ],
                'output' => [
                    'node_id' => '99',
                    'mime_type' => 'image/png',
                    'extension' => 'png',
                ],
                'placeholder_insertions' => [
                    [
                        'json_pointer' => '/11/inputs/style',
                        'placeholder' => '__STYLE__',
                        'reason' => 'style input',
                    ],
                ],
                'autoscaling_hints' => [
                    'workload_kind' => 'image',
                    'work_units_property_key' => null,
                    'slo_p95_wait_seconds' => 3.0,
                    'slo_video_seconds_per_processing_second_p95' => null,
                ],
            ]);

        $response = $this->postJson('/api/admin/studio/workflow-analyze', [
            'workflow_json' => [
                '11' => ['inputs' => ['style' => '__STYLE__'], 'class_type' => 'PromptNode'],
                '99' => ['inputs' => ['images' => ['11', 0]], 'class_type' => 'SaveImage'],
            ],
            'requested_output_kind' => 'image',
            'example_io_description' => 'Example prompt input.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.analyzer_prompt_version', 'v1')
            ->assertJsonPath('data.analyzer_schema_version', 'v1')
            ->assertJsonPath('data.result_json.properties.0.key', 'style')
            ->assertJsonPath('data.result_json.output.mime_type', 'image/png');

        $jobId = (int) $response->json('data.id');
        $this->assertTrue($jobId > 0);

        $job = WorkflowAnalysisJob::query()->findOrFail($jobId);
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertSame('v1', $job->analyzer_prompt_version);
        $this->assertSame('v1', $job->analyzer_schema_version);

        $show = $this->getJson("/api/admin/studio/workflow-analyze/{$jobId}");
        $show->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $jobId)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_marks_job_failed_when_openai_wrapper_returns_empty_payload(): void
    {
        Mockery::mock('alias:App\Models\OpenAI')
            ->shouldReceive('askChatGPT')
            ->once()
            ->andReturn(null);

        $response = $this->postJson('/api/admin/studio/workflow-analyze', [
            'workflow_json' => [
                '1' => ['inputs' => ['text' => 'hello'], 'class_type' => 'PromptNode'],
            ],
            'requested_output_kind' => 'image',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Workflow analysis failed.');

        $jobId = (int) $response->json('data.job_id');
        $this->assertTrue($jobId > 0);

        $job = WorkflowAnalysisJob::query()->findOrFail($jobId);
        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertStringContainsString('empty OpenAI response', (string) $job->error_message);
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
        DB::connection('central')->table('workflow_analysis_jobs')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
