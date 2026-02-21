#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { Aspects } from 'aws-cdk-lib';
import { AwsSolutionsChecks } from 'cdk-nag';

import { NetworkStack } from '../lib/stacks/network-stack';
import { DataStack } from '../lib/stacks/data-stack';
import { ComputeStack } from '../lib/stacks/compute-stack';
import { GpuWorkerStack } from '../lib/stacks/gpu-worker-stack';
import { MonitoringStack } from '../lib/stacks/monitoring-stack';
import { CiCdStack } from '../lib/stacks/cicd-stack';
import { STAGING_CONFIG, PRODUCTION_CONFIG, type BpEnvironmentConfig } from '../lib/config/environment';
import { FLEETS } from '../lib/config/fleets';

const app = new cdk.App();

// Select config based on context or default to staging
const stage = app.node.tryGetContext('stage') ?? 'staging';
const baseConfig = stage === 'production' ? PRODUCTION_CONFIG : STAGING_CONFIG;
const contextDomainName = app.node.tryGetContext('domainName');
const contextCertArn = app.node.tryGetContext('certificateArn');
const contextAlertEmail = app.node.tryGetContext('alertEmail');
const contextBudgetUsdRaw = app.node.tryGetContext('budgetMonthlyUsd');
const contextBudgetUsd = contextBudgetUsdRaw !== undefined && contextBudgetUsdRaw !== ''
  ? Number(contextBudgetUsdRaw)
  : undefined;
const budgetMonthlyUsd = Number.isFinite(contextBudgetUsd)
  ? contextBudgetUsd
  : baseConfig.budgetMonthlyUsd;

const config: BpEnvironmentConfig = {
  ...baseConfig,
  stage,
  domainName: contextDomainName ?? baseConfig.domainName,
  certificateArn: contextCertArn ?? baseConfig.certificateArn,
  alertEmail: contextAlertEmail ?? baseConfig.alertEmail,
  budgetMonthlyUsd,
};

const prefix = `bp-${config.stage}`;

cdk.Tags.of(app).add('Project', 'AI-Product-Manager');
cdk.Tags.of(app).add('Stage', config.stage);
const owner = app.node.tryGetContext('owner');
if (owner) {
  cdk.Tags.of(app).add('Owner', owner);
}

// --- Stack dependency chain ---
// NetworkStack -> DataStack -> ComputeStack -> GpuWorkerStack -> MonitoringStack
// CiCdStack is standalone

const network = new NetworkStack(app, `${prefix}-network`, {
  env: config.env,
  config,
  description: 'VPC, subnets, NAT, security groups',
});
cdk.Tags.of(network).add('Service', 'network');

const data = new DataStack(app, `${prefix}-data`, {
  env: config.env,
  config,
  vpc: network.vpc,
  sgRds: network.sgRds,
  sgRedis: network.sgRedis,
  description: 'RDS MariaDB, ElastiCache Redis, S3, CloudFront',
});
data.addDependency(network);
cdk.Tags.of(data).add('Service', 'data');

const compute = new ComputeStack(app, `${prefix}-compute`, {
  env: config.env,
  config,
  vpc: network.vpc,
  sgAlb: network.sgAlb,
  sgBackend: network.sgBackend,
  sgFrontend: network.sgFrontend,
  dbSecret: data.dbSecret,
  redisSecret: data.redisSecret,
  assetOpsSecret: data.assetOpsSecret,
  redisEndpoint: data.redisEndpoint,
  mediaBucket: data.mediaBucket,
  modelsBucket: data.modelsBucket,
  logsBucket: data.logsBucket,
  description: 'ECS Fargate cluster, ALB, backend + frontend services',
});
compute.addDependency(data);
cdk.Tags.of(compute).add('Service', 'compute');

const gpu = new GpuWorkerStack(app, `${prefix}-gpu`, {
  env: config.env,
  config,
  vpc: network.vpc,
  sgGpuWorkers: network.sgGpuWorkers,
  fleets: FLEETS,
  apiBaseUrl: compute.apiBaseUrl,
  modelsBucket: data.modelsBucket,
  description: 'Per-fleet GPU ASGs with Spot instances and scale-to-zero',
});
gpu.addDependency(compute);
cdk.Tags.of(gpu).add('Service', 'gpu');

const monitoring = new MonitoringStack(app, `${prefix}-monitoring`, {
  env: config.env,
  config,
  ecsCluster: compute.cluster,
  albFullName: compute.albFullName,
  albTargetGroupBackend: compute.tgBackendFullName,
  backendServiceName: compute.backendServiceName,
  frontendServiceName: compute.frontendServiceName,
  dbInstanceId: data.dbInstanceId,
  natGatewayIds: network.natGatewayIds,
  fleets: FLEETS,
  description: 'CloudWatch dashboard, alarms, SNS alerts',
});
monitoring.addDependency(gpu);
cdk.Tags.of(monitoring).add('Service', 'monitoring');

const cicd = new CiCdStack(app, `${prefix}-cicd`, {
  env: config.env,
  config,
  description: 'ECR repositories with lifecycle policies',
});
cdk.Tags.of(cicd).add('Service', 'cicd');

// Enable cdk-nag (AWS Solutions checks) in non-production for auditing
if (config.stage !== 'production') {
  Aspects.of(app).add(new AwsSolutionsChecks({ verbose: true }));
}

app.synth();
