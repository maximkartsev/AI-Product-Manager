<?php

namespace Tests\Feature;

use App\Models\LoadTestRun;
use App\Services\LoadTest\EcsRunTaskService;

class StudioLoadTestRunStartTest extends StudioLoadTestFeatureTestCase
{
    public function test_start_endpoint_transitions_run_and_persists_ecs_task_arn(): void
    {
        $this->authenticateAdmin();

        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $scenario = $this->createScenario();
        $run = $this->createRun($scenario, $environment, $revision, [
            'status' => 'queued',
            'metrics_json' => [
                'input_file_id' => 999,
                'input_payload' => [],
            ],
        ]);

        $mock = $this->mock(EcsRunTaskService::class);
        $mock->shouldReceive('launch')
            ->once()
            ->andReturn([
                'launched' => true,
                'task_arn' => 'arn:aws:ecs:us-east-1:123456789012:task/demo/started-task',
                'cluster' => 'cluster-a',
                'task_definition' => 'taskdef:1',
            ]);

        $response = $this->postJson("/api/admin/studio/load-test-runs/{$run->id}/start", []);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.run.status', 'running')
            ->assertJsonPath(
                'data.run.metrics_json.ecs_task_arn',
                'arn:aws:ecs:us-east-1:123456789012:task/demo/started-task'
            );

        $fresh = LoadTestRun::query()->findOrFail($run->id);
        $this->assertSame('running', $fresh->status);
        $this->assertSame(
            'arn:aws:ecs:us-east-1:123456789012:task/demo/started-task',
            data_get($fresh->metrics_json, 'ecs_task_arn')
        );
    }

    public function test_cancel_endpoint_is_idempotent_and_only_stops_ecs_task_once(): void
    {
        $this->authenticateAdmin();

        $workflow = $this->createWorkflow();
        $effect = $this->createEffect($workflow);
        $revision = $this->createRevision($effect, $workflow);
        $environment = $this->createExecutionEnvironment();
        $scenario = $this->createScenario();
        $run = $this->createRun($scenario, $environment, $revision, [
            'status' => 'running',
            'metrics_json' => [
                'ecs_task_arn' => 'arn:aws:ecs:us-east-1:123456789012:task/demo/running-task',
            ],
        ]);

        $mock = $this->mock(EcsRunTaskService::class);
        $mock->shouldReceive('stop')
            ->once()
            ->andReturn([
                'stopped' => true,
                'task_arn' => 'arn:aws:ecs:us-east-1:123456789012:task/demo/running-task',
                'cluster' => 'cluster-a',
            ]);

        $first = $this->postJson("/api/admin/studio/load-test-runs/{$run->id}/cancel");
        $first->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $second = $this->postJson("/api/admin/studio/load-test-runs/{$run->id}/cancel");
        $second->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $fresh = LoadTestRun::query()->findOrFail($run->id);
        $this->assertSame('cancelled', $fresh->status);
    }
}
