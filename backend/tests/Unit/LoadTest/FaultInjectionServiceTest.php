<?php

namespace Tests\Unit\LoadTest;

use App\Models\ExecutionEnvironment;
use App\Models\LoadTestRun;
use App\Models\LoadTestStage;
use App\Services\LoadTest\FaultInjectionService;
use Aws\Result;
use Mockery;
use Tests\TestCase;

class FaultInjectionServiceTest extends TestCase
{
    public function test_select_target_instance_ids_uses_ceil_sample_size_and_stable_order(): void
    {
        $service = new FaultInjectionService();

        $selected = $service->selectTargetInstanceIds([
            'i-0005',
            'i-0002',
            'i-0003',
            'i-0001',
            'i-0004',
        ], 0.5);

        $this->assertSame(['i-0001', 'i-0002', 'i-0003'], $selected);
    }

    public function test_select_target_instance_ids_returns_empty_for_invalid_inputs(): void
    {
        $service = new FaultInjectionService();

        $this->assertSame([], $service->selectTargetInstanceIds([], 0.5));
        $this->assertSame([], $service->selectTargetInstanceIds(['i-0001', 'i-0002'], 0.0));
        $this->assertSame([], $service->selectTargetInstanceIds(['i-0001', 'i-0002'], -0.2));
    }

    public function test_select_target_instance_ids_always_picks_one_when_rate_is_positive(): void
    {
        $service = new FaultInjectionService();

        $selected = $service->selectTargetInstanceIds(['i-0100', 'i-0101', 'i-0102'], 0.01);
        $allSelected = $service->selectTargetInstanceIds(['i-0100', 'i-0101', 'i-0102'], 2.0);

        $this->assertCount(1, $selected);
        $this->assertSame(['i-0100', 'i-0101', 'i-0102'], $allSelected);
    }

    public function test_inject_for_stage_resolves_asg_targets_and_starts_fis_experiment(): void
    {
        $autoScaling = Mockery::mock(\Aws\AutoScaling\AutoScalingClient::class);
        $fis = Mockery::mock(\Aws\Fis\FisClient::class);

        $autoScaling->shouldReceive('describeAutoScalingGroups')
            ->once()
            ->with(Mockery::on(function (array $input): bool {
                return ($input['AutoScalingGroupNames'] ?? [])[0] === 'asg-test-fleet';
            }))
            ->andReturn(new Result([
                'AutoScalingGroups' => [[
                    'Instances' => [
                        ['InstanceId' => 'i-0003', 'LifecycleState' => 'InService', 'HealthStatus' => 'Healthy'],
                        ['InstanceId' => 'i-0001', 'LifecycleState' => 'InService', 'HealthStatus' => 'Healthy'],
                        ['InstanceId' => 'i-0002', 'LifecycleState' => 'InService', 'HealthStatus' => 'Healthy'],
                        ['InstanceId' => 'i-standby', 'LifecycleState' => 'Standby', 'HealthStatus' => 'Healthy'],
                    ],
                ]],
            ]));

        $fis->shouldReceive('startExperiment')
            ->once()
            ->with(Mockery::on(function (array $input): bool {
                return ($input['experimentTemplateId'] ?? null) === 'tmpl-123'
                    && ($input['tags']['load_test_run_id'] ?? null) === '99'
                    && ($input['tags']['load_test_stage_id'] ?? null) === '11'
                    && ($input['tags']['asg_name'] ?? null) === 'asg-test-fleet';
            }))
            ->andReturn(new Result([
                'experiment' => [
                    'id' => 'exp-123',
                    'arn' => 'arn:aws:fis:us-east-1:123456789012:experiment/exp-123',
                    'state' => ['status' => 'initiating'],
                ],
            ]));

        $service = new FaultInjectionService($autoScaling, $fis);
        $run = new LoadTestRun([
            'execution_environment_id' => 44,
        ]);
        $run->id = 99;
        $stage = new LoadTestStage([
            'fault_enabled' => true,
            'fault_method' => 'fis',
            'fault_interruption_rate' => 0.5,
            'config_json' => [
                'fis_experiment_template_id' => 'tmpl-123',
            ],
        ]);
        $stage->id = 11;
        $environment = new ExecutionEnvironment([
            'kind' => 'test_asg',
            'fleet_slug' => 'fallback-asg-name',
            'configuration_json' => [
                'asg_name' => 'asg-test-fleet',
            ],
        ]);
        $environment->id = 44;

        $result = $service->injectForStage($run, $stage, $environment);

        $this->assertSame('started', $result['status']);
        $this->assertSame('tmpl-123', $result['template_id']);
        $this->assertSame('asg-test-fleet', $result['asg_name']);
        $this->assertSame('arn:aws:fis:us-east-1:123456789012:experiment/exp-123', $result['fis_experiment_arn']);
        $this->assertSame(['i-0001', 'i-0002'], $result['target_instance_ids']);
    }

    public function test_inject_for_stage_skips_when_no_eligible_asg_instances_exist(): void
    {
        $autoScaling = Mockery::mock(\Aws\AutoScaling\AutoScalingClient::class);
        $fis = Mockery::mock(\Aws\Fis\FisClient::class);

        $autoScaling->shouldReceive('describeAutoScalingGroups')
            ->once()
            ->andReturn(new Result([
                'AutoScalingGroups' => [[
                    'Instances' => [
                        ['InstanceId' => 'i-standby', 'LifecycleState' => 'Standby', 'HealthStatus' => 'Healthy'],
                        ['InstanceId' => 'i-unhealthy', 'LifecycleState' => 'InService', 'HealthStatus' => 'Unhealthy'],
                    ],
                ]],
            ]));
        $fis->shouldNotReceive('startExperiment');

        $service = new FaultInjectionService($autoScaling, $fis);
        $run = new LoadTestRun([
            'execution_environment_id' => 77,
        ]);
        $run->id = 101;
        $stage = new LoadTestStage([
            'fault_enabled' => true,
            'fault_method' => 'fis',
            'config_json' => [
                'fis_experiment_template_id' => 'tmpl-abc',
            ],
        ]);
        $stage->id = 5;
        $environment = new ExecutionEnvironment([
            'kind' => 'test_asg',
            'configuration_json' => [
                'asg_name' => 'asg-empty',
            ],
        ]);
        $environment->id = 77;

        $result = $service->injectForStage($run, $stage, $environment);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_active_asg_instances', $result['reason']);
        $this->assertSame('asg-empty', $result['asg_name']);
    }
}
