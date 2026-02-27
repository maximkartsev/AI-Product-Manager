import * as cdk from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as s3 from 'aws-cdk-lib/aws-s3';
import * as sns from 'aws-cdk-lib/aws-sns';
import type { BpEnvironmentConfig } from '../lib/config/environment';
import type { FleetConfig } from '../lib/config/fleets';
import { FleetAsg } from '../lib/constructs/fleet-asg';
import { GpuFleetStack } from '../lib/stacks/gpu-fleet-stack';

const testConfig: BpEnvironmentConfig = {
  env: { account: '111111111111', region: 'us-east-1' },
  centralDbName: 'bp',
  tenantPoolDbNames: ['tenant_pool_1', 'tenant_pool_2'],
  rdsInstanceClass: 't4g.small',
  rdsMultiAz: false,
  redisNodeType: 'cache.t4g.micro',
  natGateways: 1,
  backendCpu: 512,
  backendMemory: 1024,
  frontendCpu: 256,
  frontendMemory: 512,
};

const testFleet: FleetConfig = {
  slug: 'gpu-default',
  displayName: 'GPU Default',
  instanceTypes: ['g4dn.xlarge'],
  maxSize: 10,
  warmupSeconds: 300,
  backlogTarget: 2,
  scaleToZeroMinutes: 15,
};

test('FleetAsg uses new CloudWatch autoscaling contract', () => {
  const app = new cdk.App();
  const stack = new cdk.Stack(app, 'fleet-asg-test', { env: testConfig.env });

  const vpc = new ec2.Vpc(stack, 'Vpc', { maxAzs: 2, natGateways: 1 });
  const securityGroup = new ec2.SecurityGroup(stack, 'GpuWorkersSg', { vpc });
  const modelsBucket = new s3.Bucket(stack, 'ModelsBucket');
  const scaleToZeroTopic = new sns.Topic(stack, 'ScaleToZeroTopic');

  new FleetAsg(stack, 'FleetAsg', {
    vpc,
    securityGroup,
    fleet: testFleet,
    apiBaseUrl: 'https://example.com',
    fleetStage: 'staging',
    modelsBucket,
    scaleToZeroTopicArn: scaleToZeroTopic.topicArn,
  });

  const template = Template.fromStack(stack);

  template.hasResourceProperties('AWS::AutoScaling::AutoScalingGroup', Match.objectLike({
    AutoScalingGroupName: 'asg-staging-gpu-default',
    MinSize: '0',
    DesiredCapacity: '0',
  }));

  template.hasResourceProperties('AWS::CloudWatch::Alarm', Match.objectLike({
    AlarmName: 'staging-gpu-default-queue-empty',
    ComparisonOperator: 'LessThanOrEqualToThreshold',
    Threshold: 0,
    EvaluationPeriods: 15,
    AlarmActions: Match.anyValue(),
  }));

  template.hasResourceProperties('AWS::AutoScaling::ScalingPolicy', Match.objectLike({
    PolicyType: 'StepScaling',
    AdjustmentType: 'ExactCapacity',
  }));

  template.hasResourceProperties('AWS::AutoScaling::ScalingPolicy', Match.objectLike({
    PolicyType: 'TargetTrackingScaling',
    TargetTrackingConfiguration: Match.objectLike({
      CustomizedMetricSpecification: Match.objectLike({
        Namespace: 'ComfyUI/Workers',
        MetricName: 'BacklogPerInstance',
      }),
    }),
  }));

  const rendered = JSON.stringify(template.toJSON());
  expect(rendered).not.toContain('FleetSloPressureMax');
  expect(rendered).not.toContain('FleetSpotSignalCount20m');
});

test('GpuFleetStack no longer includes capacity controller lambda', () => {
  const app = new cdk.App();
  const stack = new cdk.Stack(app, 'gpu-fleet-primitives-test', { env: testConfig.env });

  const vpc = new ec2.Vpc(stack, 'Vpc', { maxAzs: 2, natGateways: 1 });
  const securityGroup = new ec2.SecurityGroup(stack, 'GpuWorkersSg', { vpc });
  const modelsBucket = new s3.Bucket(stack, 'ModelsBucket');
  const scaleToZeroTopic = new sns.Topic(stack, 'ScaleToZeroTopic');

  const fleetStack = new GpuFleetStack(app, 'gpu-fleet-stack-test', {
    env: testConfig.env,
    vpc,
    sgGpuWorkers: securityGroup,
    fleet: testFleet,
    fleetStage: 'staging',
    apiBaseUrl: 'https://example.com',
    modelsBucket,
    scaleToZeroTopicArn: scaleToZeroTopic.topicArn,
  });

  const template = Template.fromStack(fleetStack);
  template.resourceCountIs('AWS::Events::Rule', 0);

  const rendered = JSON.stringify(template.toJSON());
  expect(rendered).not.toContain('CapacityControllerFn');
  expect(rendered).not.toContain('FleetSpotSignalCount20m');
  expect(rendered).not.toContain('FleetSloPressureMax');
});
