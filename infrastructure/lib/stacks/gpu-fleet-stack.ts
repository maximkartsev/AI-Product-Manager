import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as logs from 'aws-cdk-lib/aws-logs';
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
}

export class GpuFleetStack extends cdk.Stack {
  public readonly asgName: string;

  constructor(scope: Construct, id: string, props: GpuFleetStackProps) {
    super(scope, id, props);

    const { config, vpc, sgGpuWorkers, fleet, apiBaseUrl, modelsBucket, scaleToZeroTopicArn } = props;
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

    new logs.LogRetention(this, 'GpuLogRetention', {
      logGroupName: `/gpu-workers/${fleet.slug}`,
      retention: logRetention,
    });

    new cdk.CfnOutput(this, 'AsgName', {
      value: this.asgName,
      description: `ASG name for fleet: ${fleet.slug}`,
    });
  }
}
