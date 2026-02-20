import * as cdk from 'aws-cdk-lib';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as sns from 'aws-cdk-lib/aws-sns';
import * as snsSubscriptions from 'aws-cdk-lib/aws-sns-subscriptions';
import * as cloudwatchActions from 'aws-cdk-lib/aws-cloudwatch-actions';
import * as ecs from 'aws-cdk-lib/aws-ecs';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as budgets from 'aws-cdk-lib/aws-budgets';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';
import type { BpEnvironmentConfig } from '../config/environment';
import type { FleetConfig } from '../config/fleets';

export interface MonitoringStackProps extends cdk.StackProps {
  readonly config: BpEnvironmentConfig;
  readonly ecsCluster: ecs.ICluster;
  readonly albFullName: string;
  readonly albTargetGroupBackend: string;
  readonly backendServiceName: string;
  readonly frontendServiceName: string;
  readonly dbInstanceId: string;
  readonly natGatewayIds?: string[];
  readonly fleets: FleetConfig[];
}

export class MonitoringStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props: MonitoringStackProps) {
    super(scope, id, props);

    const { config, ecsCluster, albFullName, albTargetGroupBackend, backendServiceName, frontendServiceName, dbInstanceId, natGatewayIds, fleets } = props;
    const stage = config.stage;
    const logRetention = stage === 'production' ? logs.RetentionDays.ONE_MONTH : logs.RetentionDays.ONE_WEEK;

    // ========================================
    // SNS Alert Topic
    // ========================================

    const alertTopic = new sns.Topic(this, 'AlertTopic', {
      topicName: `bp-${stage}-ops-alerts`,
    });

    if (config.alertEmail) {
      alertTopic.addSubscription(
        new snsSubscriptions.EmailSubscription(config.alertEmail)
      );
    }

    const alarmAction = new cloudwatchActions.SnsAction(alertTopic);

    // ========================================
    // GPU Worker Log Groups
    // ========================================

    for (const fleet of fleets) {
      new logs.LogGroup(this, `GpuLog-${fleet.slug}`, {
        logGroupName: `/gpu-workers/${fleet.slug}`,
        retention: logRetention,
        removalPolicy: cdk.RemovalPolicy.DESTROY,
      });
    }

    // ========================================
    // Alarms
    // ========================================

    // --- P1: Critical ---

    const alb5xxMetric = new cloudwatch.Metric({
      namespace: 'AWS/ApplicationELB',
      metricName: 'HTTPCode_ELB_5XX_Count',
      dimensionsMap: { LoadBalancer: albFullName },
      statistic: 'Sum',
      period: cdk.Duration.minutes(5),
    });

    const p1Alb5xx = new cloudwatch.Alarm(this, 'P1-Alb5xxHigh', {
      alarmName: `${stage}-p1-alb-5xx-critical`,
      metric: alb5xxMetric,
      threshold: 50,
      evaluationPeriods: 1,
      treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
    });
    p1Alb5xx.addAlarmAction(alarmAction);

    const p1RdsCpu = new cloudwatch.Alarm(this, 'P1-RdsCpuCritical', {
      alarmName: `${stage}-p1-rds-cpu-critical`,
      metric: new cloudwatch.Metric({
        namespace: 'AWS/RDS',
        metricName: 'CPUUtilization',
        dimensionsMap: { DBInstanceIdentifier: dbInstanceId },
        statistic: 'Average',
        period: cdk.Duration.minutes(10),
      }),
      threshold: 95,
      evaluationPeriods: 1,
    });
    p1RdsCpu.addAlarmAction(alarmAction);

    const p1RdsStorage = new cloudwatch.Alarm(this, 'P1-RdsStorageLow', {
      alarmName: `${stage}-p1-rds-storage-low`,
      metric: new cloudwatch.Metric({
        namespace: 'AWS/RDS',
        metricName: 'FreeStorageSpace',
        dimensionsMap: { DBInstanceIdentifier: dbInstanceId },
        statistic: 'Minimum',
        period: cdk.Duration.minutes(5),
      }),
      threshold: 2 * 1024 * 1024 * 1024, // 2 GB in bytes
      comparisonOperator: cloudwatch.ComparisonOperator.LESS_THAN_THRESHOLD,
      evaluationPeriods: 1,
    });
    p1RdsStorage.addAlarmAction(alarmAction);

    // --- P2: Warning ---

    const p2Alb5xx = new cloudwatch.Alarm(this, 'P2-Alb5xxWarning', {
      alarmName: `${stage}-p2-alb-5xx-warning`,
      metric: alb5xxMetric,
      threshold: 10,
      evaluationPeriods: 1,
      treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
    });
    p2Alb5xx.addAlarmAction(alarmAction);

    const p2RdsCpu = new cloudwatch.Alarm(this, 'P2-RdsCpuWarning', {
      alarmName: `${stage}-p2-rds-cpu-warning`,
      metric: new cloudwatch.Metric({
        namespace: 'AWS/RDS',
        metricName: 'CPUUtilization',
        dimensionsMap: { DBInstanceIdentifier: dbInstanceId },
        statistic: 'Average',
        period: cdk.Duration.minutes(5),
      }),
      threshold: 80,
      evaluationPeriods: 1,
    });
    p2RdsCpu.addAlarmAction(alarmAction);

    // --- P3: Per-fleet GPU alarms ---

    for (const fleet of fleets) {
      const dimensions = { FleetSlug: fleet.slug };

      new cloudwatch.Alarm(this, `P3-Queue-${fleet.slug}`, {
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

      new cloudwatch.Alarm(this, `P2-Error-${fleet.slug}`, {
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

    // ========================================
    // CloudWatch Dashboard
    // ========================================

    const dashboard = new cloudwatch.Dashboard(this, 'Dashboard', {
      dashboardName: `bp-${stage}`,
    });

    // Row 1: App Health
    dashboard.addWidgets(
      new cloudwatch.GraphWidget({
        title: 'ALB Requests & Errors',
        left: [
          new cloudwatch.Metric({
            namespace: 'AWS/ApplicationELB',
            metricName: 'RequestCount',
            dimensionsMap: { LoadBalancer: albFullName },
            statistic: 'Sum',
            period: cdk.Duration.minutes(5),
          }),
        ],
        right: [alb5xxMetric],
        width: 12,
      }),
      new cloudwatch.GraphWidget({
        title: 'ALB Latency',
        left: [
          new cloudwatch.Metric({
            namespace: 'AWS/ApplicationELB',
            metricName: 'TargetResponseTime',
            dimensionsMap: { LoadBalancer: albFullName },
            statistic: 'p50',
            period: cdk.Duration.minutes(5),
          }),
          new cloudwatch.Metric({
            namespace: 'AWS/ApplicationELB',
            metricName: 'TargetResponseTime',
            dimensionsMap: { LoadBalancer: albFullName },
            statistic: 'p95',
            period: cdk.Duration.minutes(5),
          }),
          new cloudwatch.Metric({
            namespace: 'AWS/ApplicationELB',
            metricName: 'TargetResponseTime',
            dimensionsMap: { LoadBalancer: albFullName },
            statistic: 'p99',
            period: cdk.Duration.minutes(5),
          }),
        ],
        width: 12,
      }),
    );

    // Row 2: Data Layer
    dashboard.addWidgets(
      new cloudwatch.GraphWidget({
        title: 'RDS CPU & Connections',
        left: [
          new cloudwatch.Metric({
            namespace: 'AWS/RDS',
            metricName: 'CPUUtilization',
            dimensionsMap: { DBInstanceIdentifier: dbInstanceId },
            statistic: 'Average',
            period: cdk.Duration.minutes(5),
          }),
        ],
        right: [
          new cloudwatch.Metric({
            namespace: 'AWS/RDS',
            metricName: 'DatabaseConnections',
            dimensionsMap: { DBInstanceIdentifier: dbInstanceId },
            statistic: 'Average',
            period: cdk.Duration.minutes(5),
          }),
        ],
        width: 12,
      }),
      new cloudwatch.GraphWidget({
        title: 'RDS Storage',
        left: [
          new cloudwatch.Metric({
            namespace: 'AWS/RDS',
            metricName: 'FreeStorageSpace',
            dimensionsMap: { DBInstanceIdentifier: dbInstanceId },
            statistic: 'Average',
            period: cdk.Duration.minutes(5),
          }),
        ],
        width: 12,
      }),
    );

    // Row 3: NAT Gateway (cost visibility)
    if (natGatewayIds && natGatewayIds.length > 0) {
      const natBytesOut = natGatewayIds.map(id => new cloudwatch.Metric({
        namespace: 'AWS/NATGateway',
        metricName: 'BytesOutToDestination',
        dimensionsMap: { NatGatewayId: id },
        statistic: 'Sum',
        period: cdk.Duration.minutes(5),
      }));
      const natBytesIn = natGatewayIds.map(id => new cloudwatch.Metric({
        namespace: 'AWS/NATGateway',
        metricName: 'BytesInFromSource',
        dimensionsMap: { NatGatewayId: id },
        statistic: 'Sum',
        period: cdk.Duration.minutes(5),
      }));

      dashboard.addWidgets(
        new cloudwatch.GraphWidget({
          title: 'NAT Gateway Data (Bytes)',
          left: natBytesOut,
          right: natBytesIn,
          width: 12,
        }),
        new cloudwatch.GraphWidget({
          title: 'NAT Gateway Port Allocation Errors',
          left: natGatewayIds.map(id => new cloudwatch.Metric({
            namespace: 'AWS/NATGateway',
            metricName: 'ErrorPortAllocation',
            dimensionsMap: { NatGatewayId: id },
            statistic: 'Sum',
            period: cdk.Duration.minutes(5),
          })),
          width: 12,
        }),
      );
    }

    // Row 4: GPU Workers (per fleet)
    for (const fleet of fleets) {
      const dimensions = { FleetSlug: fleet.slug };

      dashboard.addWidgets(
        new cloudwatch.GraphWidget({
          title: `${fleet.displayName}: Queue & Workers`,
          left: [
            new cloudwatch.Metric({
              namespace: 'ComfyUI/Workers',
              metricName: 'QueueDepth',
              dimensionsMap: dimensions,
              statistic: 'Maximum',
              period: cdk.Duration.minutes(1),
            }),
            new cloudwatch.Metric({
              namespace: 'ComfyUI/Workers',
              metricName: 'BacklogPerInstance',
              dimensionsMap: dimensions,
              statistic: 'Average',
              period: cdk.Duration.minutes(1),
            }),
          ],
          right: [
            new cloudwatch.Metric({
              namespace: 'ComfyUI/Workers',
              metricName: 'ActiveWorkers',
              dimensionsMap: dimensions,
              statistic: 'Maximum',
              period: cdk.Duration.minutes(1),
            }),
          ],
          width: 12,
        }),
        new cloudwatch.GraphWidget({
          title: `${fleet.displayName}: Performance & Errors`,
          left: [
            new cloudwatch.Metric({
              namespace: 'ComfyUI/Workers',
              metricName: 'JobProcessingP50',
              dimensionsMap: dimensions,
              statistic: 'Average',
              period: cdk.Duration.minutes(5),
            }),
          ],
          right: [
            new cloudwatch.Metric({
              namespace: 'ComfyUI/Workers',
              metricName: 'ErrorRate',
              dimensionsMap: dimensions,
              statistic: 'Average',
              period: cdk.Duration.minutes(5),
            }),
            new cloudwatch.Metric({
              namespace: 'ComfyUI/Workers',
              metricName: 'SpotInterruptionCount',
              dimensionsMap: dimensions,
              statistic: 'Sum',
              period: cdk.Duration.minutes(5),
            }),
          ],
          width: 12,
        }),
      );
    }

    // cdk-nag suppressions
    NagSuppressions.addResourceSuppressions(alertTopic, [
      { id: 'AwsSolutions-SNS2', reason: 'Ops alert topic, encryption added when needed' },
      { id: 'AwsSolutions-SNS3', reason: 'Publishers are CloudWatch alarms (AWS service), SSL enforced by AWS' },
    ]);

    // ========================================
    // Optional monthly budget (email alerts)
    // ========================================
    if (config.budgetMonthlyUsd && config.alertEmail) {
      new budgets.CfnBudget(this, 'MonthlyBudget', {
        budget: {
          budgetName: `bp-${stage}-monthly`,
          budgetType: 'COST',
          timeUnit: 'MONTHLY',
          budgetLimit: {
            amount: config.budgetMonthlyUsd.toString(),
            unit: 'USD',
          },
        },
        notificationsWithSubscribers: [
          {
            notification: {
              comparisonOperator: 'GREATER_THAN',
              threshold: 80,
              thresholdType: 'PERCENTAGE',
              notificationType: 'ACTUAL',
            },
            subscribers: [
              {
                subscriptionType: 'EMAIL',
                address: config.alertEmail,
              },
            ],
          },
          {
            notification: {
              comparisonOperator: 'GREATER_THAN',
              threshold: 100,
              thresholdType: 'PERCENTAGE',
              notificationType: 'ACTUAL',
            },
            subscribers: [
              {
                subscriptionType: 'EMAIL',
                address: config.alertEmail,
              },
            ],
          },
        ],
      });
    }
  }
}
