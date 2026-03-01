<?php

namespace Tests\Feature;

class StudioExecutorSecretTest extends StudioLoadTestFeatureTestCase
{
    public function test_submission_endpoint_rejects_requests_without_secret_header(): void
    {
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $scenario = $this->createScenario();
        $run = $this->createRun($scenario, $environment, $revision);
        $file = $this->createTenantFile($this->adminUser);

        $response = $this->postJson('/api/admin/studio/load-test/submit', [
            'load_test_run_id' => $run->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_file_id' => $file->id,
            'input_payload' => [],
            'count' => 1,
        ]);

        $response->assertStatus(401);
    }

    public function test_submission_endpoint_accepts_requests_with_valid_secret_header(): void
    {
        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $scenario = $this->createScenario();
        $run = $this->createRun($scenario, $environment, $revision);
        $file = $this->createTenantFile($this->adminUser);

        $response = $this->postJson('/api/admin/studio/load-test/submit', [
            'load_test_run_id' => $run->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'input_file_id' => $file->id,
            'input_payload' => [],
            'count' => 1,
        ], [
            'X-Studio-Executor-Secret' => 'test-secret',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }
}
