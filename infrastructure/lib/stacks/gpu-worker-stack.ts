import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as s3 from 'aws-cdk-lib/aws-s3';
import { Construct } from 'constructs';
import type { BpEnvironmentConfig } from '../config/environment';
import type { FleetConfig } from '../config/fleets';
import { FleetAsg } from '../constructs/fleet-asg';
import { ScaleToZeroLambda } from '../constructs/scale-to-zero-lambda';

export interface GpuWorkerStackProps extends cdk.StackProps {
  readonly config: BpEnvironmentConfig;
  readonly vpc: ec2.IVpc;
  readonly sgGpuWorkers: ec2.ISecurityGroup;
  readonly fleets: FleetConfig[];
  readonly apiBaseUrl: string;
  readonly modelsBucket: s3.IBucket;
  readonly scaleToZeroTopicArn?: string;
}

export class GpuWorkerStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props: GpuWorkerStackProps) {
    super(scope, id, props);

    const { config, vpc, sgGpuWorkers, fleets, apiBaseUrl, modelsBucket, scaleToZeroTopicArn } = props;
    const stage = config.stage;

    // Create per-fleet ASGs
    const fleetAsgs: Array<{
      slug: string;
      asgName: string;
      queueEmptyAlarm: cdk.aws_cloudwatch.Alarm;
    }> = [];

    for (const fleet of fleets) {
      const fleetAsg = new FleetAsg(this, `Fleet-${fleet.slug}`, {
        vpc,
        securityGroup: sgGpuWorkers,
        fleet,
        apiBaseUrl,
        stage,
        modelsBucket,
        scaleToZeroTopicArn,
      });

      fleetAsgs.push({
        slug: fleet.slug,
        asgName: fleetAsg.asg.autoScalingGroupName,
        queueEmptyAlarm: fleetAsg.queueEmptyAlarm,
      });
    }

    // Shared scale-to-zero Lambda (one Lambda for all fleets) when no shared topic is provided
    if (!scaleToZeroTopicArn) {
      new ScaleToZeroLambda(this, 'ScaleToZero', {
        stage,
        fleets: fleetAsgs,
      });
    }

    // ========================================
    // Outputs
    // ========================================

    for (const fleet of fleetAsgs) {
      new cdk.CfnOutput(this, `Asg-${fleet.slug}`, {
        value: fleet.asgName,
        description: `ASG name for fleet: ${fleet.slug}`,
      });
    }
  }
}
