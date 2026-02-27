import * as cdk from 'aws-cdk-lib';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as lambda from 'aws-cdk-lib/aws-lambda';
import * as sns from 'aws-cdk-lib/aws-sns';
import * as snsSubscriptions from 'aws-cdk-lib/aws-sns-subscriptions';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';

export interface GpuSharedStackProps extends cdk.StackProps {
}

export class GpuSharedStack extends cdk.Stack {
  public readonly scaleToZeroTopicArn: string;

  constructor(scope: Construct, id: string, props: GpuSharedStackProps) {
    super(scope, id, props);

    const topic = new sns.Topic(this, 'ScaleToZeroTopic', {
      topicName: 'bp-scale-to-zero',
    });

    const fn = new lambda.Function(this, 'ScaleToZeroFn', {
      runtime: lambda.Runtime.PYTHON_3_12,
      handler: 'index.handler',
      timeout: cdk.Duration.seconds(30),
      code: lambda.Code.fromInline(`
import json
import boto3
import re

asg_client = boto3.client('autoscaling')
pattern = re.compile(r"^(staging|production)-(.+)-queue-empty$")

def handler(event, context):
    for record in event.get('Records', []):
        message = json.loads(record['Sns']['Message'])
        alarm_name = message.get('AlarmName', '')
        new_state = message.get('NewStateValue', '')

        if new_state != 'ALARM':
            continue

        match = pattern.match(alarm_name)
        if not match:
            print(f"Alarm name did not match pattern: {alarm_name}")
            continue

        fleet_stage = match.group(1)
        fleet_slug = match.group(2)
        asg_name = f"asg-{fleet_stage}-{fleet_slug}"

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

    fn.addToRolePolicy(new iam.PolicyStatement({
      actions: ['autoscaling:SetDesiredCapacity', 'autoscaling:DescribeAutoScalingGroups'],
      resources: ['*'],
    }));

    topic.addSubscription(new snsSubscriptions.LambdaSubscription(fn));

    this.scaleToZeroTopicArn = topic.topicArn;

    new cdk.CfnOutput(this, 'ScaleToZeroTopicArn', {
      value: topic.topicArn,
      exportName: 'bp-scale-to-zero-topic-arn',
    });

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
