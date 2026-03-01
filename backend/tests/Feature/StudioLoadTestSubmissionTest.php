<?php

namespace Tests\Feature;

use App\Models\LoadTestRun;

class StudioLoadTestSubmissionTest extends StudioLoadTestFeatureTestCase
{
    public function test_submission_enqueues_multiple_dispatches_and_tags_them_with_run_id(): void
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
            'count' => 3,
        ], [
            'X-Studio-Executor-Secret' => 'test-secret',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submitted_count', 3);

        $dispatchIds = $response->json('data.dispatch_ids');
        $this->assertCount(3, $dispatchIds);

        foreach ($dispatchIds as $dispatchId) {
            $this->assertDatabaseHas('ai_job_dispatches', [
                'id' => (int) $dispatchId,
                'load_test_run_id' => $run->id,
            ], 'central');
        }

        $run = LoadTestRun::query()->findOrFail($run->id);
        $this->assertSame('studio_load_test', data_get($run->metrics_json, 'source'));
    }
}
