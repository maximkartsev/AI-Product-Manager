import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as autoscaling from 'aws-cdk-lib/aws-autoscaling';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as ssm from 'aws-cdk-lib/aws-ssm';
import * as s3 from 'aws-cdk-lib/aws-s3';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';
import type { WorkflowConfig } from '../config/workflows';

export interface WorkflowAsgProps {
  readonly vpc: ec2.IVpc;
  readonly securityGroup: ec2.ISecurityGroup;
  readonly workflow: WorkflowConfig;
  readonly apiBaseUrl: string;
  readonly stage: string;
  readonly modelsBucket: s3.IBucket;
  /** SNS topic ARN for scale-to-zero Lambda trigger */
  readonly scaleToZeroTopicArn?: string;
}

export class WorkflowAsg extends Construct {
  public readonly asg: autoscaling.AutoScalingGroup;
  public readonly queueDepthAlarm: cloudwatch.Alarm;
  public readonly queueEmptyAlarm: cloudwatch.Alarm;

  constructor(scope: Construct, id: string, props: WorkflowAsgProps) {
    super(scope, id);

    const { vpc, securityGroup, workflow, apiBaseUrl, stage, modelsBucket } = props;
    const slug = workflow.slug;

    // Resolve AMI: prefer SSM parameter, fall back to hardcoded, or use Amazon Linux placeholder
    let machineImage: ec2.IMachineImage;
    if (workflow.amiSsmParameter) {
      // Use EC2 native SSM alias syntax so new launches pick up latest AMI without redeploy.
      machineImage = ec2.MachineImage.genericLinux({
        [cdk.Aws.REGION]: `resolve:ssm:${workflow.amiSsmParameter}`,
      });
    } else if (workflow.amiId) {
      machineImage = ec2.MachineImage.genericLinux({ [cdk.Aws.REGION]: workflow.amiId });
    } else {
      // Placeholder: use Amazon Linux 2 until Packer AMI is built
      machineImage = ec2.MachineImage.latestAmazonLinux2();
    }

    // IAM role for GPU workers
    const workerRole = new iam.Role(this, 'Role', {
      assumedBy: new iam.ServicePrincipal('ec2.amazonaws.com'),
      description: `GPU worker role for workflow: ${slug}`,
      managedPolicies: [
        iam.ManagedPolicy.fromAwsManagedPolicyName('AmazonSSMManagedInstanceCore'),
      ],
    });

    // SSM read for fleet secret
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['ssm:GetParameter'],
      resources: [
        `arn:aws:ssm:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:parameter/bp/${stage}/fleet-secret`,
        `arn:aws:ssm:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:parameter/bp/${stage}/assets/${slug}/active_bundle`,
      ],
    }));

    // S3 read for ComfyUI asset bundles
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['s3:GetObject', 's3:ListBucket'],
      resources: [
        modelsBucket.bucketArn,
        `${modelsBucket.bucketArn}/bundles/${slug}/*`,
      ],
    }));

    // ASG SetInstanceProtection (for scale-in protection during job processing)
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['autoscaling:SetInstanceProtection'],
      resources: ['*'],
      conditions: {
        StringEquals: { 'autoscaling:ResourceTag/WorkflowSlug': slug },
      },
    }));

    // CloudWatch Logs
    workerRole.addToPolicy(new iam.PolicyStatement({
      actions: ['logs:CreateLogGroup', 'logs:CreateLogStream', 'logs:PutLogEvents'],
      resources: [`arn:aws:logs:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:log-group:/gpu-workers/${slug}:*`],
    }));

    // Active bundle pointer for this workflow
    new ssm.StringParameter(this, 'ActiveBundleParam', {
      parameterName: `/bp/${stage}/assets/${slug}/active_bundle`,
      stringValue: 'none',
      description: `Active ComfyUI asset bundle for ${slug} (${stage})`,
    });

    // User data script
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
      '# Resolve active asset bundle prefix for this workflow (optional)',
      `ACTIVE_PREFIX=$(aws ssm get-parameter --name "/bp/${stage}/assets/${slug}/active_bundle" --query "Parameter.Value" --output text --region ${cdk.Aws.REGION} 2>/dev/null || echo "none")`,
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
      '    echo "Syncing ComfyUI assets: prefix=$ACTIVE_PREFIX"',
      '    aws s3 sync "s3://$MODELS_BUCKET/$ACTIVE_PREFIX/models/" /opt/comfyui/models/ --delete || true',
      '    aws s3 sync "s3://$MODELS_BUCKET/$ACTIVE_PREFIX/custom_nodes/" /opt/comfyui/custom_nodes/ --delete || true',
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
      `WORKFLOW_SLUGS="${slug}"`,
      `ASG_NAME="asg-${stage}-${slug}"`,
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

    // Launch template
    const launchTemplate = new ec2.LaunchTemplate(this, 'Lt', {
      launchTemplateName: `lt-${stage}-${slug}`,
      machineImage,
      role: workerRole,
      userData,
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

    // ASG with mixed instances policy
    this.asg = new autoscaling.AutoScalingGroup(this, 'Asg', {
      autoScalingGroupName: `asg-${stage}-${slug}`,
      vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      mixedInstancesPolicy: {
        instancesDistribution: {
          onDemandBaseCapacity: 0,
          onDemandPercentageAboveBaseCapacity: 0,
          spotAllocationStrategy: autoscaling.SpotAllocationStrategy.CAPACITY_OPTIMIZED_PRIORITIZED,
        },
        launchTemplate,
        launchTemplateOverrides: workflow.instanceTypes.map(t => ({
          instanceType: new ec2.InstanceType(t),
        })),
      },
      minCapacity: 0,
      maxCapacity: workflow.maxSize,
      desiredCapacity: 0,
      defaultInstanceWarmup: cdk.Duration.seconds(workflow.warmupSeconds ?? 300),
      capacityRebalance: true,
      newInstancesProtectedFromScaleIn: false,
    });

    cdk.Tags.of(this.asg).add('WorkflowSlug', slug);

    // ========================================
    // Scaling Policies
    // ========================================

    const namespace = 'ComfyUI/Workers';
    const dimensions = { WorkflowSlug: slug };

    // QueueDepth metric
    const queueDepthMetric = new cloudwatch.Metric({
      namespace,
      metricName: 'QueueDepth',
      dimensionsMap: dimensions,
      statistic: 'Maximum',
      period: cdk.Duration.minutes(1),
    });

    // Step scaling 0 -> 1: alarm when QueueDepth > 0
    this.queueDepthAlarm = new cloudwatch.Alarm(this, 'QueueDepthAlarm', {
      alarmName: `${stage}-${slug}-queue-has-jobs`,
      metric: queueDepthMetric,
      threshold: 0,
      comparisonOperator: cloudwatch.ComparisonOperator.GREATER_THAN_THRESHOLD,
      evaluationPeriods: 1,
      treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
    });

    this.asg.scaleOnMetric('StepScale', {
      metric: queueDepthMetric,
      adjustmentType: autoscaling.AdjustmentType.EXACT_CAPACITY,
      scalingSteps: [
        { lower: 1, change: 1 },   // QueueDepth >= 1: at least 1 instance
      ],
      cooldown: cdk.Duration.minutes(3),
    });

    // Target tracking 1 -> N: BacklogPerInstance target
    const backlogMetric = new cloudwatch.Metric({
      namespace,
      metricName: 'BacklogPerInstance',
      dimensionsMap: dimensions,
      statistic: 'Average',
      period: cdk.Duration.minutes(1),
    });

    this.asg.scaleOnMetric('BacklogTracking', {
      metric: backlogMetric,
      adjustmentType: autoscaling.AdjustmentType.CHANGE_IN_CAPACITY,
      scalingSteps: [
        { upper: 2, change: 0 },    // Backlog <= 2: stable
        { lower: 2, change: 1 },    // Backlog > 2: add 1
        { lower: 5, change: 2 },    // Backlog > 5: add 2
      ],
      cooldown: cdk.Duration.minutes(3),
    });

    // Scale-to-zero alarm: QueueDepth == 0 for 15 minutes
    this.queueEmptyAlarm = new cloudwatch.Alarm(this, 'QueueEmptyAlarm', {
      alarmName: `${stage}-${slug}-queue-empty`,
      metric: queueDepthMetric,
      threshold: 0,
      comparisonOperator: cloudwatch.ComparisonOperator.LESS_THAN_OR_EQUAL_TO_THRESHOLD,
      evaluationPeriods: workflow.scaleToZeroMinutes ?? 15,
      datapointsToAlarm: workflow.scaleToZeroMinutes ?? 15,
      treatMissingData: cloudwatch.TreatMissingData.BREACHING, // If no data, assume empty
    });

    // cdk-nag suppressions
    NagSuppressions.addResourceSuppressions(workerRole, [
      { id: 'AwsSolutions-IAM4', reason: 'SSM Managed Instance Core is required for Session Manager access' },
      { id: 'AwsSolutions-IAM5', reason: 'SetInstanceProtection scoped by resource tag condition' },
    ], true);
    NagSuppressions.addResourceSuppressions(this.asg, [
      { id: 'AwsSolutions-AS3', reason: 'ASG notifications handled via MonitoringStack SNS' },
    ]);
  }
}
