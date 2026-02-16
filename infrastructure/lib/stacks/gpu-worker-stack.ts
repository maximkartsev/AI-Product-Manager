import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import { Construct } from 'constructs';
import type { BpEnvironmentConfig } from '../config/environment';
import type { WorkflowConfig } from '../config/workflows';
import { WorkflowAsg } from '../constructs/workflow-asg';
import { ScaleToZeroLambda } from '../constructs/scale-to-zero-lambda';

export interface GpuWorkerStackProps extends cdk.StackProps {
  readonly config: BpEnvironmentConfig;
  readonly vpc: ec2.IVpc;
  readonly sgGpuWorkers: ec2.ISecurityGroup;
  readonly workflows: WorkflowConfig[];
  readonly apiBaseUrl: string;
}

export class GpuWorkerStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props: GpuWorkerStackProps) {
    super(scope, id, props);

    const { config, vpc, sgGpuWorkers, workflows, apiBaseUrl } = props;
    const stage = config.stage;

    // Create per-workflow ASGs
    const workflowAsgs: Array<{
      slug: string;
      asgName: string;
      queueEmptyAlarm: cdk.aws_cloudwatch.Alarm;
    }> = [];

    for (const workflow of workflows) {
      const wfAsg = new WorkflowAsg(this, `Wf-${workflow.slug}`, {
        vpc,
        securityGroup: sgGpuWorkers,
        workflow,
        apiBaseUrl,
        stage,
      });

      workflowAsgs.push({
        slug: workflow.slug,
        asgName: wfAsg.asg.autoScalingGroupName,
        queueEmptyAlarm: wfAsg.queueEmptyAlarm,
      });
    }

    // Shared scale-to-zero Lambda (one Lambda for all workflows)
    new ScaleToZeroLambda(this, 'ScaleToZero', {
      stage,
      workflows: workflowAsgs,
    });

    // ========================================
    // Outputs
    // ========================================

    for (const wf of workflowAsgs) {
      new cdk.CfnOutput(this, `Asg-${wf.slug}`, {
        value: wf.asgName,
        description: `ASG name for workflow: ${wf.slug}`,
      });
    }
  }
}
