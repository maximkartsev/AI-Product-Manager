<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\File;
use App\Models\Workflow;
use App\Services\WorkflowPayloadService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowPayloadServiceTest extends TestCase
{
    protected static bool $prepared = false;

    private WorkflowPayloadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$prepared) {
            try {
                DB::connection('central')->statement(
                    'CREATE DATABASE IF NOT EXISTS tenant_pool_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
            } catch (\Throwable $e) {
                // ignore
            }

            Artisan::call('migrate');
            Artisan::call('tenancy:pools-migrate');
            static::$prepared = true;
        }

        $this->resetState();
        $this->service = new WorkflowPayloadService();

        Storage::fake('s3');
        config(['services.comfyui.workflow_disk' => 's3']);
        config(['filesystems.default' => 's3']);
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('effects')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createWorkflow(array $overrides = []): Workflow
    {
        $uid = uniqid();
        $defaults = [
            'name' => 'Workflow ' . $uid,
            'slug' => 'workflow-' . $uid,
            'is_active' => true,
            'properties' => [],
        ];

        return Workflow::query()->create(array_merge($defaults, $overrides));
    }

    private function createEffect(array $overrides = []): Effect
    {
        $uid = uniqid();
        $defaults = [
            'name' => 'Effect ' . $uid,
            'slug' => 'effect-' . $uid,
            'type' => 'video',
            'credits_cost' => 5,
            'popularity_score' => 0,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
        ];

        return Effect::query()->create(array_merge($defaults, $overrides));
    }

    private function seedWorkflowJson(string $path, array $data): void
    {
        Storage::disk('s3')->put($path, json_encode($data));
    }

    private function makeInputFile(array $overrides = []): File
    {
        $file = new File();
        $file->path = $overrides['path'] ?? 'uploads/video.mp4';
        $file->disk = $overrides['disk'] ?? 's3';
        $file->original_filename = $overrides['original_filename'] ?? 'my-video.mp4';
        $file->mime_type = $overrides['mime_type'] ?? 'video/mp4';
        return $file;
    }

    // ========================================================================
    // resolveProperties tests
    // ========================================================================

    public function test_resolve_properties_layers_default_then_override_then_user_input(): void
    {
        $workflow = $this->createWorkflow([
            'properties' => [
                [
                    'key' => 'prompt',
                    'type' => 'text',
                    'default_value' => 'default prompt',
                    'user_configurable' => true,
                    'placeholder' => '{{PROMPT}}',
                ],
                [
                    'key' => 'strength',
                    'type' => 'text',
                    'default_value' => '0.5',
                    'user_configurable' => true,
                    'placeholder' => '{{STRENGTH}}',
                ],
            ],
        ]);

        $effect = $this->createEffect([
            'workflow_id' => $workflow->id,
            'property_overrides' => ['prompt' => 'effect override'],
        ]);

        // User input overrides effect override for 'prompt'
        $result = $this->service->resolveProperties(
            $workflow,
            $effect,
            ['prompt' => 'user input']
        );

        $this->assertSame('user input', $result['prompt']);
        // 'strength' has no override or user input, so uses default
        $this->assertSame('0.5', $result['strength']);
    }

    public function test_resolve_properties_skips_primary_input(): void
    {
        $workflow = $this->createWorkflow([
            'properties' => [
                [
                    'key' => 'input_video',
                    'type' => 'video',
                    'is_primary_input' => true,
                    'placeholder' => '{{INPUT}}',
                ],
                [
                    'key' => 'prompt',
                    'type' => 'text',
                    'default_value' => 'hello',
                    'placeholder' => '{{PROMPT}}',
                ],
            ],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $result = $this->service->resolveProperties($workflow, $effect);

        $this->assertArrayNotHasKey('input_video', $result);
        $this->assertArrayHasKey('prompt', $result);
    }

    public function test_resolve_properties_ignores_user_input_for_non_configurable(): void
    {
        $workflow = $this->createWorkflow([
            'properties' => [
                [
                    'key' => 'locked',
                    'type' => 'text',
                    'default_value' => 'fixed',
                    'user_configurable' => false,
                    'placeholder' => '{{LOCKED}}',
                ],
            ],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $result = $this->service->resolveProperties($workflow, $effect, ['locked' => 'overridden']);

        $this->assertSame('fixed', $result['locked']);
    }

    public function test_resolve_properties_with_empty_properties(): void
    {
        $workflow = $this->createWorkflow(['properties' => []]);
        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $result = $this->service->resolveProperties($workflow, $effect);

        $this->assertSame([], $result);
    }

    // ========================================================================
    // buildJobPayload tests
    // ========================================================================

    public function test_build_job_payload_loads_workflow_json_and_replaces_text_placeholders(): void
    {
        $path = 'workflows/test/workflow.json';
        $this->seedWorkflowJson($path, [
            'node1' => ['inputs' => ['text' => '{{PROMPT}}']],
        ]);

        $workflow = $this->createWorkflow([
            'comfyui_workflow_path' => $path,
            'output_node_id' => '5',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'properties' => [
                [
                    'key' => 'prompt',
                    'type' => 'text',
                    'default_value' => 'default',
                    'placeholder' => '{{PROMPT}}',
                    'user_configurable' => true,
                ],
            ],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $payload = $this->service->buildJobPayload($effect, ['prompt' => 'my custom prompt'], null);

        $this->assertSame('my custom prompt', $payload['workflow']['node1']['inputs']['text']);
    }

    public function test_build_job_payload_adds_primary_input_to_assets(): void
    {
        $path = 'workflows/test/workflow.json';
        $this->seedWorkflowJson($path, ['node1' => ['inputs' => []]]);

        $workflow = $this->createWorkflow([
            'comfyui_workflow_path' => $path,
            'output_node_id' => '5',
            'properties' => [
                [
                    'key' => 'input_video',
                    'type' => 'video',
                    'is_primary_input' => true,
                    'placeholder' => '{{INPUT}}',
                ],
            ],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);
        $inputFile = $this->makeInputFile();

        $payload = $this->service->buildJobPayload($effect, [], $inputFile);

        $primaryAssets = array_filter($payload['assets'], fn($a) => $a['is_primary_input'] === true);
        $this->assertCount(1, $primaryAssets);
        $primary = array_values($primaryAssets)[0];
        $this->assertSame('uploads/video.mp4', $primary['s3_path']);
    }

    public function test_build_job_payload_adds_image_video_properties_to_assets(): void
    {
        $path = 'workflows/test/workflow.json';
        $this->seedWorkflowJson($path, ['node1' => ['inputs' => []]]);

        $workflow = $this->createWorkflow([
            'comfyui_workflow_path' => $path,
            'properties' => [
                [
                    'key' => 'bg_image',
                    'type' => 'image',
                    'default_value' => 'assets/bg.png',
                    'placeholder' => '{{BG}}',
                ],
            ],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $payload = $this->service->buildJobPayload($effect, ['bg_image' => 'assets/bg.png'], null);

        $imageAssets = array_filter($payload['assets'], fn($a) => $a['type'] === 'image');
        $this->assertCount(1, $imageAssets);
        $img = array_values($imageAssets)[0];
        $this->assertSame('assets/bg.png', $img['s3_path']);
        $this->assertFalse($img['is_primary_input']);
    }

    public function test_build_job_payload_sets_output_config_from_workflow(): void
    {
        $path = 'workflows/test/workflow.json';
        $this->seedWorkflowJson($path, ['node1' => []]);

        $workflow = $this->createWorkflow([
            'comfyui_workflow_path' => $path,
            'output_node_id' => '42',
            'output_extension' => 'gif',
            'output_mime_type' => 'image/gif',
            'properties' => [],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $payload = $this->service->buildJobPayload($effect, [], null);

        $this->assertSame('42', $payload['output_node_id']);
        $this->assertSame('gif', $payload['output_extension']);
        $this->assertSame('image/gif', $payload['output_mime_type']);
    }

    public function test_build_job_payload_sets_input_metadata_from_file(): void
    {
        $path = 'workflows/test/workflow.json';
        $this->seedWorkflowJson($path, ['node1' => []]);

        $workflow = $this->createWorkflow([
            'comfyui_workflow_path' => $path,
            'properties' => [
                [
                    'key' => 'input',
                    'type' => 'video',
                    'is_primary_input' => true,
                    'placeholder' => '{{INPUT_PATH}}',
                ],
            ],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);
        $inputFile = $this->makeInputFile([
            'original_filename' => 'my-video.mp4',
            'mime_type' => 'video/mp4',
        ]);

        $payload = $this->service->buildJobPayload($effect, [], $inputFile);

        $this->assertSame('my-video.mp4', $payload['input_name']);
        $this->assertSame('video/mp4', $payload['input_mime_type']);
        $this->assertSame('{{INPUT_PATH}}', $payload['input_path_placeholder']);
    }

    public function test_build_job_payload_throws_when_workflow_json_missing(): void
    {
        $workflow = $this->createWorkflow([
            'comfyui_workflow_path' => 'nonexistent/path.json',
            'properties' => [],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->buildJobPayload($effect, [], null);
    }

    public function test_build_job_payload_throws_when_workflow_json_invalid(): void
    {
        $path = 'workflows/test/bad.json';
        Storage::disk('s3')->put($path, 'not valid json {{{');

        $workflow = $this->createWorkflow([
            'comfyui_workflow_path' => $path,
            'properties' => [],
        ]);

        $effect = $this->createEffect(['workflow_id' => $workflow->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->buildJobPayload($effect, [], null);
    }

    public function test_build_job_payload_throws_when_effect_has_no_workflow(): void
    {
        $effect = $this->createEffect(['workflow_id' => null]);

        $this->expectException(\RuntimeException::class);
        $this->service->buildJobPayload($effect, [], null);
    }
}
