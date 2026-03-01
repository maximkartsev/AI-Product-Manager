<?php

namespace App\Services\LoadTest;

use App\Models\ExecutionEnvironment;
use App\Models\LoadTestRun;
use App\Models\LoadTestStage;
use Aws\AutoScaling\AutoScalingClient;
use Aws\Fis\FisClient;
use Illuminate\Support\Str;

class FaultInjectionService
{
    public function __construct(
        private readonly ?AutoScalingClient $autoScalingClient = null,
        private readonly ?FisClient $fisClient = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function injectForStage(
        LoadTestRun $run,
        LoadTestStage $stage,
        ?ExecutionEnvironment $environment = null
    ): array
    {
        if (!$stage->fault_enabled) {
            return [
                'status' => 'skipped',
                'reason' => 'fault_disabled',
            ];
        }

        if ((string) ($stage->fault_method ?? 'fis') !== 'fis') {
            return [
                'status' => 'skipped',
                'reason' => 'unsupported_fault_method',
                'fault_method' => $stage->fault_method,
            ];
        }

        $environment = $environment ?: ExecutionEnvironment::query()->find((int) $run->execution_environment_id);
        if (!$environment || (string) $environment->kind !== 'test_asg') {
            return [
                'status' => 'skipped',
                'reason' => 'invalid_execution_environment',
            ];
        }

        $stageConfig = is_array($stage->config_json) ? $stage->config_json : [];
        $environmentConfig = is_array($environment->configuration_json) ? $environment->configuration_json : [];
        $templateId = (string) (
            $stageConfig['fis_experiment_template_id']
            ?? $environmentConfig['fis_experiment_template_id']
            ?? env('AWS_FIS_EXPERIMENT_TEMPLATE_ID', '')
        );
        if ($templateId === '') {
            return [
                'status' => 'skipped',
                'reason' => 'fis_template_not_configured',
            ];
        }

        $asgName = (string) ($environmentConfig['asg_name'] ?? $environment->fleet_slug ?? '');
        if ($asgName === '') {
            return [
                'status' => 'skipped',
                'reason' => 'asg_name_not_configured',
            ];
        }

        $instanceIds = $this->resolveAsgInstanceIds($asgName);
        if (empty($instanceIds)) {
            return [
                'status' => 'skipped',
                'reason' => 'no_active_asg_instances',
                'asg_name' => $asgName,
            ];
        }

        $interruptionRate = (float) ($stage->fault_interruption_rate ?? 0.0);
        if ($interruptionRate <= 0) {
            $interruptionRate = 0.1;
        }
        $targetInstanceIds = $this->selectTargetInstanceIds($instanceIds, $interruptionRate);
        if (empty($targetInstanceIds)) {
            return [
                'status' => 'skipped',
                'reason' => 'no_targets_selected',
                'asg_name' => $asgName,
            ];
        }

        try {
            $result = $this->fis()->startExperiment([
                'experimentTemplateId' => $templateId,
                'clientToken' => 'studio-load-test-' . $run->id . '-' . $stage->id . '-' . Str::uuid()->toString(),
                'tags' => [
                    'source' => 'studio_load_test',
                    'load_test_run_id' => (string) $run->id,
                    'load_test_stage_id' => (string) $stage->id,
                    'asg_name' => $asgName,
                ],
            ]);

            $experiment = $result->get('experiment') ?? [];
            $experimentArn = data_get($experiment, 'arn');

            return [
                'status' => 'started',
                'fault_method' => 'fis',
                'template_id' => $templateId,
                'asg_name' => $asgName,
                'target_instance_ids' => $targetInstanceIds,
                'experiment_id' => data_get($experiment, 'id'),
                'fis_experiment_arn' => $experimentArn,
                'experiment_arn' => $experimentArn,
                'state' => data_get($experiment, 'state.status'),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'fault_method' => 'fis',
                'template_id' => $templateId,
                'asg_name' => $asgName,
                'target_instance_ids' => $targetInstanceIds,
                'message' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * @param array<int, string> $instanceIds
     * @return array<int, string>
     */
    public function selectTargetInstanceIds(array $instanceIds, float $interruptionRate): array
    {
        $instanceIds = array_values(array_filter($instanceIds, static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $count = count($instanceIds);
        if ($count === 0 || $interruptionRate <= 0) {
            return [];
        }

        sort($instanceIds);
        $sampleSize = max(1, (int) ceil($count * $interruptionRate));

        return array_slice($instanceIds, 0, min($count, $sampleSize));
    }

    /**
     * @return array<int, string>
     */
    private function resolveAsgInstanceIds(string $asgName): array
    {
        $response = $this->autoScaling()->describeAutoScalingGroups([
            'AutoScalingGroupNames' => [$asgName],
        ]);
        $groups = (array) $response->get('AutoScalingGroups');
        $group = $groups[0] ?? null;
        if (!is_array($group)) {
            return [];
        }

        $instances = (array) ($group['Instances'] ?? []);
        $instanceIds = [];
        foreach ($instances as $instance) {
            if (!is_array($instance)) {
                continue;
            }
            $lifecycle = (string) ($instance['LifecycleState'] ?? '');
            $health = (string) ($instance['HealthStatus'] ?? '');
            $id = (string) ($instance['InstanceId'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($lifecycle !== 'InService') {
                continue;
            }
            if ($health !== '' && $health !== 'Healthy') {
                continue;
            }
            $instanceIds[] = $id;
        }

        return $instanceIds;
    }

    private function autoScaling(): AutoScalingClient
    {
        if ($this->autoScalingClient) {
            return $this->autoScalingClient;
        }

        return new AutoScalingClient([
            'region' => (string) config('services.comfyui.aws_region', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'version' => 'latest',
        ]);
    }

    private function fis(): FisClient
    {
        if ($this->fisClient) {
            return $this->fisClient;
        }

        return new FisClient([
            'region' => (string) config('services.comfyui.aws_region', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'version' => 'latest',
        ]);
    }
}
