import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as autoscaling from 'aws-cdk-lib/aws-autoscaling';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as s3 from 'aws-cdk-lib/aws-s3';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';
import type { FleetConfig } from '../config/fleets';

export interface FleetAsgProps {
  readonly vpc: ec2.IVpc;
  readonly securityGroup: ec2.ISecurityGroup;
  readonly fleet: FleetConfig;
  readonly apiBaseUrl: string;
  readonly stage: string;
  readonly modelsBucket: s3.IBucket;
  /** SNS topic ARN for scale-to-zero Lambda trigger */
  readonly scaleToZeroTopicArn?: string;
}

export class FleetAsg extends Construct {
  public readonly asg: autoscaling.AutoScalingGroup;
  public readonly onDemandAsg: autoscaling.AutoScalingGroup;
  public readonly queueDepthAlarm: cloudwatch.Alarm;
  public readonly queueEmptyAlarm?: cloudwatch.Alarm;

  constructor(scope: Construct, id: string, props: FleetAsgProps) {
    super(scope, id);

    const { vpc, securityGroup, fleet, apiBaseUrl, stage, modelsBucket } = props;
    const fleetSlug = fleet.slug;

    // Resolve AMI: prefer an explicit SSM parameter, then an explicit AMI ID, otherwise default to
    // /bp/ami/fleets/<stage>/<fleetSlug>.
    //
    // NOTE: MachineImage.genericLinux requires a concrete region key (e.g. "us-east-1"), so we
    // must use the stack's resolved region instead of cdk.Aws.REGION (a token).
    const region = cdk.Stack.of(this).region;
    let machineImage: ec2.IMachineImage;
    const amiParam = fleet.amiSsmParameter;
    if (amiParam) {
      machineImage = ec2.MachineImage.genericLinux({
        [region]: `resolve:ssm:${amiParam}`,
      });
    } else if (fleet.amiId) {
      machineImage = ec2.MachineImage.genericLinux({ [region]: fleet.amiId });
    } else {
      machineImage = ec2.MachineImage.genericLinux({
        [region]: `resolve:ssm:/bp/ami/fleets/${stage}/${fleetSlug}`,
      });
    }

    // IAM role for GPU workers
    const workerRole = new iam.Role(this, 'Role', {
      assumedBy: new iam.ServicePrincipal('ec2.amazonaws.com'),
      description: `GPU worker role for fleet: ${fleetSlug}`,
      managedPolicies: [
        iam.ManagedPolicy.fromAwsManagedPolicyName('AmazonSSMManagedInstanceCore'),
      ],
    });

    // SSM read for fleet secret + active bundle
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['ssm:GetParameter'],
      resources: [
        `arn:aws:ssm:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:parameter/bp/${stage}/fleet-secret`,
        `arn:aws:ssm:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:parameter/bp/${stage}/fleets/${fleetSlug}/active_bundle`,
      ],
    }));

    // S3 read for ComfyUI assets + bundle manifests
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['s3:GetObject'],
      resources: [
        `${modelsBucket.bucketArn}/assets/*`,
        `${modelsBucket.bucketArn}/bundles/*/manifest.json`,
      ],
    }));

    // ASG SetInstanceProtection (for scale-in protection during job processing)
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['autoscaling:SetInstanceProtection'],
      resources: ['*'],
      conditions: {
        StringEquals: { 'autoscaling:ResourceTag/FleetSlug': fleetSlug },
      },
    }));

    // CloudWatch Logs
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['logs:CreateLogGroup', 'logs:CreateLogStream', 'logs:PutLogEvents'],
      resources: [`arn:aws:logs:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:log-group:/gpu-workers/${fleetSlug}:*`],
    }));

    const spotAsgName = `asg-${stage}-${fleetSlug}`;
    const onDemandAsgName = `asg-${stage}-${fleetSlug}-od`;

    const buildUserData = (asgName: string): ec2.UserData => {
      const userData = ec2.UserData.forLinux();
      userData.addCommands(
      '#!/bin/bash',
      'set -euo pipefail',
      'ASSET_LOG="/var/log/comfyui-asset-sync.log"',
      'touch "$ASSET_LOG"',
      'exec > >(tee -a "$ASSET_LOG") 2>&1',
      '',
      '# Fetch fleet secret from SSM',
      `FLEET_SECRET=$(aws ssm get-parameter --name "/bp/${stage}/fleet-secret" --with-decryption --query "Parameter.Value" --output text --region ${cdk.Aws.REGION})`,
      '',
      '# Resolve active asset bundle prefix for this fleet (optional)',
      `ACTIVE_PREFIX=$(aws ssm get-parameter --name "/bp/${stage}/fleets/${fleetSlug}/active_bundle" --query "Parameter.Value" --output text --region ${cdk.Aws.REGION} 2>/dev/null || echo "none")`,
      'ACTIVE_PREFIX=${ACTIVE_PREFIX%/}',
      'BAKED_BUNDLE_ID=""',
      'if [ -f /opt/comfyui/.baked_bundle_id ]; then',
      '  BAKED_BUNDLE_ID=$(cat /opt/comfyui/.baked_bundle_id | tr -d "\\r\\n ")',
      'fi',
      `MODELS_BUCKET="${modelsBucket.bucketName}"`,
      'if [ -n "$ACTIVE_PREFIX" ] && [ "$ACTIVE_PREFIX" != "none" ]; then',
      '  ACTIVE_BUNDLE_ID=$(basename "$ACTIVE_PREFIX")',
      '  if [ -n "$BAKED_BUNDLE_ID" ] && [ "$ACTIVE_BUNDLE_ID" = "$BAKED_BUNDLE_ID" ]; then',
      '    echo "Active bundle matches baked bundle ($ACTIVE_BUNDLE_ID); skipping asset sync."',
      '  else',
      '    if [ -x /opt/comfyui/bin/apply-bundle.sh ]; then',
      '      echo "Applying bundle from manifest: prefix=$ACTIVE_PREFIX"',
      '      MODELS_BUCKET="$MODELS_BUCKET" BUNDLE_PREFIX="$ACTIVE_PREFIX" /opt/comfyui/bin/apply-bundle.sh',
      '    else',
      '      echo "Bundle installer missing: /opt/comfyui/bin/apply-bundle.sh"',
      '    fi',
      '  fi',
      'else',
      '  echo "No active bundle set; skipping asset sync."',
      'fi',
      '',
      '# Get instance ID from metadata (IMDSv2)',
      'TOKEN=$(curl -s -X PUT "http://169.254.169.254/latest/api/token" -H "X-aws-ec2-metadata-token-ttl-seconds: 300")',
      'INSTANCE_ID=$(curl -s -H "X-aws-ec2-metadata-token: $TOKEN" http://169.254.169.254/latest/meta-data/instance-id)',
      '',
      '# Ensure worker service is stopped before writing env',
      'systemctl stop comfyui-worker.service || true',
      '',
      '# Start ComfyUI via systemd',
      'systemctl start comfyui.service',
      '',
      '# Wait for ComfyUI to be ready',
      'for i in $(seq 1 60); do',
      '  curl -s http://127.0.0.1:8188/system_stats && break',
      '  sleep 5',
      'done',
      '',
      '# Write worker environment file',
      'cat > /opt/worker/env <<EOF',
      `API_BASE_URL="${apiBaseUrl}"`,
      'WORKER_ID="$INSTANCE_ID"',
      'FLEET_SECRET="$FLEET_SECRET"',
      `FLEET_SLUG="${fleetSlug}"`,
      `FLEET_STAGE="${stage}"`,
      `ASG_NAME="${asgName}"`,
      'COMFYUI_BASE_URL="http://127.0.0.1:8188"',
      'POLL_INTERVAL_SECONDS=3',
      'HEARTBEAT_INTERVAL_SECONDS=30',
      'MAX_CONCURRENCY=1',
      'EOF',
      'chown ubuntu:ubuntu /opt/worker/env',
      'chmod 600 /opt/worker/env',
      '',
      '# Start worker via systemd',
      'systemctl restart comfyui-worker.service',
      );
      return userData;
    };

    // Launch templates
    const spotLaunchTemplate = new ec2.LaunchTemplate(this, 'LtSpot', {
      launchTemplateName: `lt-${stage}-${fleetSlug}`,
      machineImage,
      role: workerRole,
      userData: buildUserData(spotAsgName),
      securityGroup,
      requireImdsv2: true,
      httpPutResponseHopLimit: 1,
      blockDevices: [
        {
          deviceName: '/dev/sda1',
          volume: ec2.BlockDeviceVolume.ebs(100, {
            volumeType: ec2.EbsDeviceVolumeType.GP3,
            encrypted: true,
          }),
        },
      ],
    });

    const onDemandLaunchTemplate = new ec2.LaunchTemplate(this, 'LtOnDemand', {
      launchTemplateName: `lt-${stage}-${fleetSlug}-od`,
      machineImage,
      role: workerRole,
      userData: buildUserData(onDemandAsgName),
      securityGroup,
      requireImdsv2: true,
      httpPutResponseHopLimit: 1,
      blockDevices: [
        {
          deviceName: '/dev/sda1',
          volume: ec2.BlockDeviceVolume.ebs(100, {
            volumeType: ec2.EbsDeviceVolumeType.GP3,
            encrypted: true,
          }),
        },
      ],
    });

    // Spot ASG with mixed instances policy
    this.asg = new autoscaling.AutoScalingGroup(this, 'AsgSpot', {
      autoScalingGroupName: spotAsgName,
      vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      mixedInstancesPolicy: {
        instancesDistribution: {
          onDemandBaseCapacity: 0,
          onDemandPercentageAboveBaseCapacity: 0,
          spotAllocationStrategy: autoscaling.SpotAllocationStrategy.CAPACITY_OPTIMIZED_PRIORITIZED,
        },
        launchTemplate: spotLaunchTemplate,
        launchTemplateOverrides: fleet.instanceTypes.map(t => ({
          instanceType: new ec2.InstanceType(t),
        })),
      },
      minCapacity: 1,
      maxCapacity: fleet.maxSize,
      desiredCapacity: 1,
      defaultInstanceWarmup: cdk.Duration.seconds(fleet.warmupSeconds ?? 300),
      capacityRebalance: true,
      newInstancesProtectedFromScaleIn: false,
    });

    // On-demand ASG (fallback)
    this.onDemandAsg = new autoscaling.AutoScalingGroup(this, 'AsgOnDemand', {
      autoScalingGroupName: onDemandAsgName,
      vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      mixedInstancesPolicy: {
        instancesDistribution: {
          onDemandBaseCapacity: 0,
          onDemandPercentageAboveBaseCapacity: 100,
          spotAllocationStrategy: autoscaling.SpotAllocationStrategy.CAPACITY_OPTIMIZED_PRIORITIZED,
        },
        launchTemplate: onDemandLaunchTemplate,
        launchTemplateOverrides: fleet.instanceTypes.map(t => ({
          instanceType: new ec2.InstanceType(t),
        })),
      },
      minCapacity: 0,
      maxCapacity: fleet.maxSize,
      desiredCapacity: 0,
      defaultInstanceWarmup: cdk.Duration.seconds(fleet.warmupSeconds ?? 300),
      capacityRebalance: false,
      newInstancesProtectedFromScaleIn: false,
    });

    cdk.Tags.of(this.asg).add('FleetSlug', fleetSlug);
    cdk.Tags.of(this.asg).add('CapacityType', 'spot');
    cdk.Tags.of(this.onDemandAsg).add('FleetSlug', fleetSlug);
    cdk.Tags.of(this.onDemandAsg).add('CapacityType', 'on-demand');

    // ========================================
    // Scaling Policies
    // ========================================

    const namespace = 'ComfyUI/Workers';
    const dimensions = { FleetSlug: fleetSlug };

    // QueueDepth metric
    const queueDepthMetric = new cloudwatch.Metric({
      namespace,
      metricName: 'QueueDepth',
      dimensionsMap: dimensions,
      statistic: 'Maximum',
      period: cdk.Duration.minutes(1),
    });

    // Queue depth alarm retained for visibility
    this.queueDepthAlarm = new cloudwatch.Alarm(this, 'QueueDepthAlarm', {
      alarmName: `${stage}-${fleetSlug}-queue-has-jobs`,
      metric: queueDepthMetric,
      threshold: 0,
      evaluationPeriods: 1,
      comparisonOperator: cloudwatch.ComparisonOperator.GREATER_THAN_THRESHOLD,
      treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
    });

    // Scale 1 -> N using FleetSloPressureMax (SLO pressure)
    const sloPressureMetric = new cloudwatch.Metric({
      namespace,
      metricName: 'FleetSloPressureMax',
      dimensionsMap: dimensions,
      statistic: 'Maximum',
      period: cdk.Duration.minutes(1),
    });

    this.asg.scaleOnMetric('SloPressureScaling', {
      metric: sloPressureMetric,
      adjustmentType: autoscaling.AdjustmentType.CHANGE_IN_CAPACITY,
      scalingSteps: [
        { upper: 0.7, change: -1 },
        { lower: 1, change: 1 },
        { lower: 1.5, change: 2 },
      ],
      cooldown: cdk.Duration.minutes(3),
    });

    NagSuppressions.addResourceSuppressions(workerRole, [
      { id: 'AwsSolutions-IAM4', reason: 'Uses AWS managed policies for SSM' },
      { id: 'AwsSolutions-IAM5', reason: 'Autoscaling protection requires wildcard permissions' },
    ], true);
    NagSuppressions.addResourceSuppressions(this.asg, [
      { id: 'AwsSolutions-AS3', reason: 'ASG notifications handled via MonitoringStack SNS' },
    ]);
  }
}
