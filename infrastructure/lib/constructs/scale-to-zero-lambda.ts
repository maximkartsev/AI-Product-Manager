import * as cdk from 'aws-cdk-lib';
import * as lambda from 'aws-cdk-lib/aws-lambda';
import * as sns from 'aws-cdk-lib/aws-sns';
import * as snsSubscriptions from 'aws-cdk-lib/aws-sns-subscriptions';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import * as cloudwatchActions from 'aws-cdk-lib/aws-cloudwatch-actions';
import * as iam from 'aws-cdk-lib/aws-iam';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';

export interface ScaleToZeroLambdaProps {
  readonly stage: string;
  /** Map of workflow slug -> { asgName, queueEmptyAlarm } */
  readonly workflows: Array<{
    slug: string;
    asgName: string;
    queueEmptyAlarm: cloudwatch.Alarm;
  }>;
}

/**
 * Shared Lambda function that sets ASG desired capacity to 0
 * when triggered by SNS (from CloudWatch alarm: QueueDepth == 0 for 15 min).
 */
export class ScaleToZeroLambda extends Construct {
  constructor(scope: Construct, id: string, props: ScaleToZeroLambdaProps) {
    super(scope, id);

    if (props.workflows.length === 0) return;

    // SNS topic for scale-to-zero triggers
    const topic = new sns.Topic(this, 'Topic', {
      topicName: `bp-${props.stage}-scale-to-zero`,
    });

    // Lambda to set desired capacity to 0
    const fn = new lambda.Function(this, 'Fn', {
      runtime: lambda.Runtime.PYTHON_3_12,
      handler: 'index.handler',
      timeout: cdk.Duration.seconds(30),
      code: lambda.Code.fromInline(`
import json
import boto3
import re

asg_client = boto3.client('autoscaling')

# Map alarm name patterns to ASG names
ASG_MAP = ${JSON.stringify(
  Object.fromEntries(props.workflows.map(w => [w.queueEmptyAlarm.alarmName, w.asgName]))
)}

def handler(event, context):
    for record in event.get('Records', []):
        message = json.loads(record['Sns']['Message'])
        alarm_name = message.get('AlarmName', '')
        new_state = message.get('NewStateValue', '')

        if new_state != 'ALARM':
            continue

        asg_name = ASG_MAP.get(alarm_name)
        if not asg_name:
            print(f"Unknown alarm: {alarm_name}")
            continue

        # Check current desired capacity before setting to 0
        response = asg_client.describe_auto_scaling_groups(
            AutoScalingGroupNames=[asg_name]
        )
        groups = response.get('AutoScalingGroups', [])
        if not groups:
            print(f"ASG not found: {asg_name}")
            continue

        current = groups[0].get('DesiredCapacity', 0)
        if current == 0:
            print(f"ASG {asg_name} already at 0")
            continue

        asg_client.set_desired_capacity(
            AutoScalingGroupName=asg_name,
            DesiredCapacity=0,
            HonorCooldown=False,
        )
        print(f"Scaled {asg_name} to 0 (was {current})")
`),
    });

    // Grant Lambda permission to manage ASG capacity
    const asgArns = props.workflows.map(w =>
      `arn:aws:autoscaling:*:*:autoScalingGroup:*:autoScalingGroupName/${w.asgName}`
    );
    fn.addToRolePolicy(new iam.PolicyStatement({
      actions: ['autoscaling:SetDesiredCapacity', 'autoscaling:DescribeAutoScalingGroups'],
      resources: ['*'], // DescribeAutoScalingGroups doesn't support resource-level permissions
    }));

    // Subscribe Lambda to SNS topic
    topic.addSubscription(new snsSubscriptions.LambdaSubscription(fn));

    // Connect each workflow's queue-empty alarm to SNS
    for (const workflow of props.workflows) {
      workflow.queueEmptyAlarm.addAlarmAction(new cloudwatchActions.SnsAction(topic));
    }

    NagSuppressions.addResourceSuppressions(topic, [
      { id: 'AwsSolutions-SNS2', reason: 'Internal-only topic for scale-to-zero alarms' },
      { id: 'AwsSolutions-SNS3', reason: 'Internal-only topic, publishers are CloudWatch alarms (AWS service)' },
    ]);
    NagSuppressions.addResourceSuppressions(fn, [
      { id: 'AwsSolutions-IAM4', reason: 'Lambda basic execution role' },
      { id: 'AwsSolutions-IAM5', reason: 'DescribeAutoScalingGroups requires wildcard' },
      { id: 'AwsSolutions-L1', reason: 'Python 3.12 is current' },
    ], true);
  }
}
