import * as cdk from 'aws-cdk-lib';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as cw_actions from 'aws-cdk-lib/aws-cloudwatch-actions';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as sns from 'aws-cdk-lib/aws-sns';
import * as s3 from 'aws-cdk-lib/aws-s3';
import { Construct } from 'constructs';
import type { BpEnvironmentConfig } from '../config/environment';
import type { FleetConfig } from '../config/fleets';
import { FleetAsg } from '../constructs/fleet-asg';

export interface GpuFleetStackProps extends cdk.StackProps {
  readonly config: BpEnvironmentConfig;
  readonly vpc: ec2.IVpc;
  readonly sgGpuWorkers: ec2.ISecurityGroup;
  readonly fleet: FleetConfig;
  readonly apiBaseUrl: string;
  readonly modelsBucket: s3.IBucket;
  readonly scaleToZeroTopicArn?: string;
  /** Optional: SNS topic ARN for ops alerts (MonitoringStack) */
  readonly opsAlertTopicArn?: string;
}

export class GpuFleetStack extends cdk.Stack {
  public readonly asgName: string;

  constructor(scope: Construct, id: string, props: GpuFleetStackProps) {
    super(scope, id, props);

    const { config, vpc, sgGpuWorkers, fleet, apiBaseUrl, modelsBucket, scaleToZeroTopicArn, opsAlertTopicArn } = props;
    const stage = config.stage;

    const fleetAsg = new FleetAsg(this, `Fleet-${fleet.slug}`, {
      vpc,
      securityGroup: sgGpuWorkers,
      fleet,
      apiBaseUrl,
      stage,
      modelsBucket,
      scaleToZeroTopicArn,
    });

    this.asgName = fleetAsg.asg.autoScalingGroupName;

    const logRetention = stage === 'production'
      ? logs.RetentionDays.ONE_MONTH
      : logs.RetentionDays.ONE_WEEK;

    new logs.LogGroup(this, 'GpuWorkerLogGroup', {
      logGroupName: `/gpu-workers/${fleet.slug}`,
      retention: logRetention,
    });

    if (opsAlertTopicArn) {
      const opsTopic = sns.Topic.fromTopicArn(this, 'OpsAlertsTopic', opsAlertTopicArn);
      const alarmAction = new cw_actions.SnsAction(opsTopic);

      const dimensions = { FleetSlug: fleet.slug, Stage: stage };

      new cloudwatch.Alarm(this, 'QueueDeepAlarm', {
        alarmName: `${stage}-p3-${fleet.slug}-queue-deep`,
        metric: new cloudwatch.Metric({
          namespace: 'ComfyUI/Workers',
          metricName: 'QueueDepth',
          dimensionsMap: dimensions,
          statistic: 'Maximum',
          period: cdk.Duration.minutes(30),
        }),
        threshold: 10,
        evaluationPeriods: 1,
        treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
      }).addAlarmAction(alarmAction);

      new cloudwatch.Alarm(this, 'ErrorRateAlarm', {
        alarmName: `${stage}-p2-${fleet.slug}-error-rate`,
        metric: new cloudwatch.Metric({
          namespace: 'ComfyUI/Workers',
          metricName: 'ErrorRate',
          dimensionsMap: dimensions,
          statistic: 'Average',
          period: cdk.Duration.minutes(5),
        }),
        threshold: 20,
        evaluationPeriods: 1,
        treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
      }).addAlarmAction(alarmAction);
    }

    new cdk.CfnOutput(this, 'AsgName', {
      value: this.asgName,
      description: `ASG name for fleet: ${fleet.slug}`,
    });
  }
}
