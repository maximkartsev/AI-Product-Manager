<?php

namespace App\Services\LoadTesting;

use App\Models\LoadTestRun;

class EcsLoadTestTaskLauncher
{
    /**
     * @return array<string, mixed>
     */
    public function launch(LoadTestRun $run): array
    {
        $cluster = (string) config('services.comfyui.load_test_runner_cluster', '');
        $taskDefinition = (string) config('services.comfyui.load_test_runner_task_definition', '');
        $subnets = $this->listFromCsv((string) config('services.comfyui.load_test_runner_subnets', ''));
        $securityGroups = $this->listFromCsv((string) config('services.comfyui.load_test_runner_security_groups', ''));

        if ($cluster === '' || $taskDefinition === '' || empty($subnets) || empty($securityGroups)) {
            return [
                'launched' => false,
                'reason' => 'runner_task_not_configured',
            ];
        }

        try {
            $client = new \Aws\Ecs\EcsClient([
                'region' => (string) config('services.comfyui.aws_region', env('AWS_DEFAULT_REGION', 'us-east-1')),
                'version' => 'latest',
            ]);

            $result = $client->runTask([
                'cluster' => $cluster,
                'taskDefinition' => $taskDefinition,
                'count' => 1,
                'launchType' => 'FARGATE',
                'networkConfiguration' => [
                    'awsvpcConfiguration' => [
                        'subnets' => $subnets,
                        'securityGroups' => $securityGroups,
                        'assignPublicIp' => 'DISABLED',
                    ],
                ],
                'overrides' => [
                    'containerOverrides' => [[
                        'name' => (string) config('services.comfyui.load_test_runner_container_name', 'runner'),
                        'command' => ['php', 'artisan', 'studio:run-load-test', '--run-id=' . $run->id],
                    ]],
                ],
                'tags' => [
                    ['key' => 'source', 'value' => 'studio-load-test'],
                    ['key' => 'load-test-run-id', 'value' => (string) $run->id],
                ],
            ]);

            $task = data_get($result->toArray(), 'tasks.0', []);
            $failure = data_get($result->toArray(), 'failures.0', null);

            if (!empty($failure)) {
                return [
                    'launched' => false,
                    'reason' => 'ecs_run_task_failure',
                    'failure' => $failure,
                ];
            }

            return [
                'launched' => true,
                'task_arn' => data_get($task, 'taskArn'),
                'cluster' => $cluster,
                'task_definition' => $taskDefinition,
            ];
        } catch (\Throwable $e) {
            return [
                'launched' => false,
                'reason' => 'ecs_launch_exception',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(?string $taskArn, string $reason = 'cancelled_by_operator'): array
    {
        $cluster = (string) config('services.comfyui.load_test_runner_cluster', '');
        if ($taskArn === null || trim($taskArn) === '') {
            return [
                'stopped' => false,
                'reason' => 'task_arn_missing',
            ];
        }
        if ($cluster === '') {
            return [
                'stopped' => false,
                'reason' => 'runner_task_not_configured',
            ];
        }

        try {
            $client = new \Aws\Ecs\EcsClient([
                'region' => (string) config('services.comfyui.aws_region', env('AWS_DEFAULT_REGION', 'us-east-1')),
                'version' => 'latest',
            ]);
            $result = $client->stopTask([
                'cluster' => $cluster,
                'task' => $taskArn,
                'reason' => $reason,
            ]);

            return [
                'stopped' => true,
                'task_arn' => data_get($result->toArray(), 'task.taskArn', $taskArn),
                'cluster' => $cluster,
            ];
        } catch (\Throwable $e) {
            return [
                'stopped' => false,
                'reason' => 'ecs_stop_exception',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    private function listFromCsv(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $item) => trim($item))
            ->filter(fn (string $item) => $item !== '')
            ->values()
            ->all();
    }
}

