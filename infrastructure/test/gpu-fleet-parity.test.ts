import * as cdk from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as s3 from 'aws-cdk-lib/aws-s3';
import * as sns from 'aws-cdk-lib/aws-sns';
import { expect, test } from '@jest/globals';
import type { FleetConfig } from '../lib/config/fleets';
import { FleetAsg } from '../lib/constructs/fleet-asg';

const testFleet: FleetConfig = {
  slug: 'gpu-default',
  displayName: 'GPU Default',
  instanceTypes: ['g4dn.xlarge'],
  maxSize: 10,
  warmupSeconds: 300,
  backlogTarget: 2,
  scaleToZeroMinutes: 15,
};

test('staging and production fleet constructs keep autoscaling topology parity', () => {
  const app = new cdk.App();
  const stack = new cdk.Stack(app, 'fleet-parity-test', {
    env: { account: '111111111111', region: 'us-east-1' },
  });

  const vpc = new ec2.Vpc(stack, 'Vpc', { maxAzs: 2, natGateways: 1 });
  const securityGroup = new ec2.SecurityGroup(stack, 'GpuWorkersSg', { vpc });
  const modelsBucket = new s3.Bucket(stack, 'ModelsBucket');
  const scaleToZeroTopic = new sns.Topic(stack, 'ScaleToZeroTopic');

  new FleetAsg(stack, 'StagingFleetAsg', {
    vpc,
    securityGroup,
    fleet: testFleet,
    apiBaseUrl: 'https://example.com',
    stage: 'staging',
    modelsBucket,
    scaleToZeroTopicArn: scaleToZeroTopic.topicArn,
  });

  new FleetAsg(stack, 'ProductionFleetAsg', {
    vpc,
    securityGroup,
    fleet: testFleet,
    apiBaseUrl: 'https://example.com',
    stage: 'production',
    modelsBucket,
    scaleToZeroTopicArn: scaleToZeroTopic.topicArn,
  });

  const template = Template.fromStack(stack);

  // 0->1 and scale-to-zero behavior must stay parity across staging and production constructs.
  template.resourceCountIs('AWS::AutoScaling::AutoScalingGroup', 2);

  const alarms = Object.values(template.findResources('AWS::CloudWatch::Alarm'));
  const queueEmptyAlarms = alarms.filter((resource) => {
    const alarmName = (resource as { Properties?: { AlarmName?: string } }).Properties?.AlarmName;
    return alarmName === 'staging-gpu-default-queue-empty' || alarmName === 'production-gpu-default-queue-empty';
  });
  expect(queueEmptyAlarms).toHaveLength(2);

  template.hasResourceProperties('AWS::CloudWatch::Alarm', Match.objectLike({
    AlarmName: 'staging-gpu-default-queue-empty',
    ComparisonOperator: 'LessThanOrEqualToThreshold',
    EvaluationPeriods: 15,
  }));
  template.hasResourceProperties('AWS::CloudWatch::Alarm', Match.objectLike({
    AlarmName: 'production-gpu-default-queue-empty',
    ComparisonOperator: 'LessThanOrEqualToThreshold',
    EvaluationPeriods: 15,
  }));

  const scalingPolicies = Object.values(template.findResources('AWS::AutoScaling::ScalingPolicy'));
  const stepScaling = scalingPolicies.filter(
    (resource) => (resource as { Properties?: { PolicyType?: string } }).Properties?.PolicyType === 'StepScaling',
  );
  const targetTracking = scalingPolicies.filter(
    (resource) => (resource as { Properties?: { PolicyType?: string } }).Properties?.PolicyType === 'TargetTrackingScaling',
  );

  // CDK expands each step policy into two resources for upper/lower intervals.
  expect(stepScaling).toHaveLength(4);
  expect(targetTracking).toHaveLength(2);

  for (const policy of stepScaling) {
    expect((policy as { Properties?: { AdjustmentType?: string } }).Properties?.AdjustmentType).toBe('ExactCapacity');
  }

  for (const policy of targetTracking) {
    const metricSpec = (policy as {
      Properties?: { TargetTrackingConfiguration?: { CustomizedMetricSpecification?: { Namespace?: string; MetricName?: string } } };
    }).Properties?.TargetTrackingConfiguration?.CustomizedMetricSpecification;
    expect(metricSpec?.Namespace).toBe('ComfyUI/Workers');
    expect(metricSpec?.MetricName).toBe('BacklogPerInstance');
  }

  const rendered = JSON.stringify(template.toJSON());
  expect(rendered).not.toContain('FleetSloPressureMax');
  expect(rendered).not.toContain('FleetSpotSignalCount20m');
});

