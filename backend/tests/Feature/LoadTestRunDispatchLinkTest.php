<?php

namespace Tests\Feature;

class LoadTestRunDispatchLinkTest extends StudioLoadTestFeatureTestCase
{
    public function test_load_test_submission_sets_dispatch_load_test_run_id(): void
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

        $response->assertStatus(201);
        $dispatchId = (int) $response->json('data.dispatch_ids.0');
        $this->assertTrue($dispatchId > 0);

        $this->assertDatabaseHas('ai_job_dispatches', [
            'id' => $dispatchId,
            'load_test_run_id' => $run->id,
        ], 'central');
    }
}
