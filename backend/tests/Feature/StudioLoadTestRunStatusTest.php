<?php

namespace Tests\Feature;

use App\Models\AiJobDispatch;

class StudioLoadTestRunStatusTest extends StudioLoadTestFeatureTestCase
{
    public function test_status_endpoint_returns_compact_progress_payload(): void
    {
        $this->authenticateAdmin();

        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $scenario = $this->createScenario();
        $run = $this->createRun($scenario, $environment, $revision, [
            'status' => 'running',
            'success_count' => 2,
            'failure_count' => 1,
            'p95_latency_ms' => 420.5,
            'queue_wait_p95_seconds' => 1.75,
            'processing_p95_seconds' => 2.25,
            'achieved_rps' => 1.5,
            'achieved_rpm' => 90.0,
            'metrics_json' => [
                'total_submitted_dispatches' => 4,
                'ecs_task_arn' => 'arn:aws:ecs:us-east-1:123456789012:task/demo/abc',
                'fault_events' => [
                    [
                        'status' => 'started',
                        'fis_experiment_arn' => 'arn:aws:fis:us-east-1:123456789012:experiment/exp-1',
                        'target_instance_ids' => ['i-abc123'],
                    ],
                ],
            ],
        ]);

        AiJobDispatch::query()->create([
            'tenant_id' => (string) $this->tenant->id,
            'tenant_job_id' => 201,
            'workflow_id' => $workflow->id,
            'load_test_run_id' => $run->id,
            'stage' => 'test',
            'status' => 'completed',
            'priority' => 0,
            'attempts' => 0,
        ]);
        AiJobDispatch::query()->create([
            'tenant_id' => (string) $this->tenant->id,
            'tenant_job_id' => 202,
            'workflow_id' => $workflow->id,
            'load_test_run_id' => $run->id,
            'stage' => 'test',
            'status' => 'failed',
            'priority' => 0,
            'attempts' => 0,
        ]);
        AiJobDispatch::query()->create([
            'tenant_id' => (string) $this->tenant->id,
            'tenant_job_id' => 203,
            'workflow_id' => $workflow->id,
            'load_test_run_id' => $run->id,
            'stage' => 'test',
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);
        AiJobDispatch::query()->create([
            'tenant_id' => (string) $this->tenant->id,
            'tenant_job_id' => 204,
            'workflow_id' => $workflow->id,
            'load_test_run_id' => $run->id,
            'stage' => 'test',
            'status' => 'leased',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $response = $this->getJson("/api/admin/studio/load-test-runs/{$run->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.submitted_count', 4)
            ->assertJsonPath('data.completed_count', 1)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.queued_count', 1)
            ->assertJsonPath('data.leased_count', 1)
            ->assertJsonPath('data.p95_latency_ms', 420.5)
            ->assertJsonPath('data.ecs_task_arn', 'arn:aws:ecs:us-east-1:123456789012:task/demo/abc');
    }
}
