import { Environment } from 'aws-cdk-lib';

export interface BpEnvironmentConfig {
  /** AWS environment (account + region) */
  readonly env: Environment;

  /** e.g. 'staging', 'production' */
  readonly stage: string;

  /** Primary domain name (e.g. app.example.com). Leave empty to skip HTTPS/cert setup. */
  readonly domainName?: string;

  /** ACM certificate ARN for ALB HTTPS. Required if domainName is set. */
  readonly certificateArn?: string;

  /** Email for ops alerts SNS subscription */
  readonly alertEmail?: string;

  /** Optional monthly budget (USD) for cost alerts */
  readonly budgetMonthlyUsd?: number;

  /** Central database name */
  readonly centralDbName: string;

  /** Tenant pool database names */
  readonly tenantPoolDbNames: string[];

  /** RDS instance class (e.g. 'db.t4g.small') */
  readonly rdsInstanceClass?: string;

  /** Whether to enable Multi-AZ for RDS */
  readonly rdsMultiAz?: boolean;

  /** Redis node type */
  readonly redisNodeType?: string;

  /** Number of NAT Gateways (1 for cost savings, 2 for HA) */
  readonly natGateways?: number;

  /** Backend task vCPU (in CDK units: 256 = 0.25 vCPU) */
  readonly backendCpu?: number;

  /** Backend task memory (MiB) */
  readonly backendMemory?: number;

  /** Frontend task vCPU */
  readonly frontendCpu?: number;

  /** Frontend task memory (MiB) */
  readonly frontendMemory?: number;
}

export const STAGING_CONFIG: BpEnvironmentConfig = {
  env: {
    account: process.env.CDK_DEFAULT_ACCOUNT,
    region: process.env.CDK_DEFAULT_REGION ?? 'us-east-1',
  },
  stage: 'staging',
  alertEmail: process.env.ALERT_EMAIL,
  centralDbName: 'bp',
  tenantPoolDbNames: ['tenant_pool_1', 'tenant_pool_2'],
  rdsInstanceClass: 'db.t4g.small',
  rdsMultiAz: false,
  redisNodeType: 'cache.t4g.micro',
  natGateways: 1,
  backendCpu: 512,
  backendMemory: 1024,
  frontendCpu: 256,
  frontendMemory: 512,
};

export const PRODUCTION_CONFIG: BpEnvironmentConfig = {
  env: {
    account: process.env.CDK_DEFAULT_ACCOUNT,
    region: process.env.CDK_DEFAULT_REGION ?? 'us-east-1',
  },
  stage: 'production',
  alertEmail: process.env.ALERT_EMAIL,
  centralDbName: 'bp',
  tenantPoolDbNames: ['tenant_pool_1', 'tenant_pool_2'],
  rdsInstanceClass: 'db.t4g.medium',
  rdsMultiAz: true,
  redisNodeType: 'cache.t4g.small',
  natGateways: 2,
  backendCpu: 1024,
  backendMemory: 2048,
  frontendCpu: 512,
  frontendMemory: 1024,
};
