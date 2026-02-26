import * as cdk from 'aws-cdk-lib';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as cw_actions from 'aws-cdk-lib/aws-cloudwatch-actions';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as events from 'aws-cdk-lib/aws-events';
import * as targets from 'aws-cdk-lib/aws-events-targets';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as lambda from 'aws-cdk-lib/aws-lambda';
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

    const capacityController = new lambda.Function(this, 'CapacityControllerFn', {
      runtime: lambda.Runtime.PYTHON_3_12,
      handler: 'index.handler',
      timeout: cdk.Duration.seconds(30),
      environment: {
        STAGE: stage,
        FLEET_SLUG: fleet.slug,
        SPOT_ASG_NAME: fleetAsg.asg.autoScalingGroupName,
        ON_DEMAND_ASG_NAME: fleetAsg.onDemandAsg.autoScalingGroupName,
        SLO_PRESSURE_THRESHOLD: '1',
      },
      code: lambda.Code.fromInline(`
import datetime
import os

import boto3

asg = boto3.client("autoscaling")
cw = boto3.client("cloudwatch")

spot_asg = os.environ.get("SPOT_ASG_NAME", "")
od_asg = os.environ.get("ON_DEMAND_ASG_NAME", "")
fleet_slug = os.environ.get("FLEET_SLUG", "")
stage = os.environ.get("STAGE", "")
slo_threshold = float(os.environ.get("SLO_PRESSURE_THRESHOLD", "1") or "1")


def _get_group(name: str):
    if not name:
        return None
    resp = asg.describe_auto_scaling_groups(AutoScalingGroupNames=[name])
    groups = resp.get("AutoScalingGroups", [])
    return groups[0] if groups else None


def _in_service_count(group):
    return len([i for i in group.get("Instances", []) if i.get("LifecycleState") == "InService"])


def _get_metric_value(metric_name: str) -> float:
    if not metric_name or not fleet_slug:
        return 0.0
    now = datetime.datetime.utcnow()
    dimensions = [{"Name": "FleetSlug", "Value": fleet_slug}]
    if stage:
        dimensions.append({"Name": "Stage", "Value": stage})
    resp = cw.get_metric_statistics(
        Namespace="ComfyUI/Workers",
        MetricName=metric_name,
        Dimensions=dimensions,
        StartTime=now - datetime.timedelta(minutes=10),
        EndTime=now,
        Period=60,
        Statistics=["Maximum"],
    )
    points = resp.get("Datapoints", [])
    if not points:
        return 0.0
    latest = max(points, key=lambda x: x["Timestamp"])
    return float(latest.get("Maximum", 0) or 0)


def handler(event, context):
    spot_group = _get_group(spot_asg)
    od_group = _get_group(od_asg)
    if not spot_group or not od_group:
        print("Missing ASG(s).")
        return

    spot_desired = int(spot_group.get("DesiredCapacity", 0))
    spot_in_service = _in_service_count(spot_group)
    od_desired = int(od_group.get("DesiredCapacity", 0))
    od_max = int(od_group.get("MaxSize", 0))

    target_total = max(1, spot_desired)
    shortfall = max(0, target_total - spot_in_service)

    new_od_desired = od_desired
    if shortfall > 0:
        new_od_desired = max(od_desired, shortfall)
    elif od_desired > 0:
        spot_signals = _get_metric_value("FleetSpotSignalCount20m")
        slo_pressure = _get_metric_value("FleetSloPressureMax")
        if spot_signals == 0 and slo_pressure <= slo_threshold:
            new_od_desired = max(0, od_desired - 1)

    if new_od_desired > od_max:
        new_od_desired = od_max

    if new_od_desired != od_desired:
        asg.set_desired_capacity(
            AutoScalingGroupName=od_asg,
            DesiredCapacity=new_od_desired,
            HonorCooldown=False,
        )
        print(f"Updated on-demand desired: {od_desired} -> {new_od_desired}")
    else:
        print("No capacity change.")
`),
    });

    capacityController.addToRolePolicy(new iam.PolicyStatement({
      actions: [
        'autoscaling:DescribeAutoScalingGroups',
        'autoscaling:SetDesiredCapacity',
        'cloudwatch:GetMetricStatistics',
      ],
      resources: ['*'],
    }));

    new events.Rule(this, 'CapacityControllerSchedule', {
      schedule: events.Schedule.rate(cdk.Duration.minutes(2)),
      targets: [new targets.LambdaFunction(capacityController)],
    });

    const logRetention = stage === 'production'
      ? logs.RetentionDays.ONE_MONTH
      : logs.RetentionDays.ONE_WEEK;

    new logs.LogRetention(this, 'GpuLogRetention', {
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
