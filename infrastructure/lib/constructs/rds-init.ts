import * as cdk from 'aws-cdk-lib';
import * as lambda from 'aws-cdk-lib/aws-lambda';
import * as cr from 'aws-cdk-lib/custom-resources';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as rds from 'aws-cdk-lib/aws-rds';
import * as secretsmanager from 'aws-cdk-lib/aws-secretsmanager';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';

export interface RdsInitProps {
  readonly vpc: ec2.IVpc;
  readonly dbInstance: rds.IDatabaseInstance;
  readonly dbSecret: secretsmanager.ISecret;
  readonly securityGroup: ec2.ISecurityGroup;
  /** Additional database names to create (e.g. ['tenant_pool_1', 'tenant_pool_2']) */
  readonly additionalDatabases: string[];
}

/**
 * Custom resource that runs a Lambda to create additional databases
 * on the RDS instance after it's provisioned.
 */
export class RdsInit extends Construct {
  constructor(scope: Construct, id: string, props: RdsInitProps) {
    super(scope, id);

    if (props.additionalDatabases.length === 0) return;

    const fn = new lambda.Function(this, 'RdsInitFn', {
      runtime: lambda.Runtime.PYTHON_3_12,
      handler: 'index.handler',
      timeout: cdk.Duration.minutes(2),
      vpc: props.vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      securityGroups: [props.securityGroup],
      environment: {
        SECRET_ARN: props.dbSecret.secretArn,
      },
      code: lambda.Code.fromInline(`
import json
import os
import boto3
import pymysql

def handler(event, context):
    if event.get('RequestType') == 'Delete':
        return {'PhysicalResourceId': event.get('PhysicalResourceId', 'rds-init')}

    secret_arn = os.environ['SECRET_ARN']
    databases = json.loads(event['ResourceProperties']['Databases'])

    # Fetch credentials from Secrets Manager
    sm = boto3.client('secretsmanager')
    secret = json.loads(sm.get_secret_value(SecretId=secret_arn)['SecretString'])

    conn = pymysql.connect(
        host=secret['host'],
        port=int(secret.get('port', 3306)),
        user=secret['username'],
        password=secret['password'],
        connect_timeout=10,
    )
    try:
        with conn.cursor() as cursor:
            for db_name in databases:
                # Sanitize: only allow alphanumeric and underscores
                safe_name = ''.join(c for c in db_name if c.isalnum() or c == '_')
                cursor.execute("CREATE DATABASE IF NOT EXISTS " + safe_name + " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
                print(f"Created database: {safe_name}")
        conn.commit()
    finally:
        conn.close()

    return {'PhysicalResourceId': 'rds-init'}
`),
      // pymysql is bundled via a Lambda layer or inline-installable
      // For production, use a Lambda layer with pymysql
    });

    // Grant the Lambda access to read the DB secret
    props.dbSecret.grantRead(fn);

    // Custom resource provider
    const provider = new cr.Provider(this, 'Provider', {
      onEventHandler: fn,
    });

    new cdk.CustomResource(this, 'Resource', {
      serviceToken: provider.serviceToken,
      properties: {
        Databases: JSON.stringify(props.additionalDatabases),
        // Change this to force re-run if databases change
        Version: props.additionalDatabases.join(','),
      },
    });

    NagSuppressions.addResourceSuppressions(fn, [
      { id: 'AwsSolutions-IAM4', reason: 'Lambda basic execution role is acceptable for init script' },
      { id: 'AwsSolutions-L1', reason: 'Python 3.12 is current' },
    ], true);
    NagSuppressions.addResourceSuppressions(provider, [
      { id: 'AwsSolutions-IAM4', reason: 'CR provider framework-managed role' },
      { id: 'AwsSolutions-IAM5', reason: 'CR provider framework-managed role' },
      { id: 'AwsSolutions-L1', reason: 'CR provider framework uses default runtime' },
    ], true);
  }
}
