import * as cdk from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { ComputeStack } from '../lib/stacks/compute-stack';
import { DataStack } from '../lib/stacks/data-stack';
import { NetworkStack } from '../lib/stacks/network-stack';
import type { BpEnvironmentConfig } from '../lib/config/environment';

const baseConfig: BpEnvironmentConfig = {
  env: { account: '111111111111', region: 'us-east-1' },
  stage: 'staging',
  centralDbName: 'bp',
  tenantPoolDbNames: ['tenant_pool_1', 'tenant_pool_2'],
  rdsInstanceClass: 't4g.small',
  rdsMultiAz: false,
  redisNodeType: 'cache.t4g.micro',
  natGateways: 1,
  backendCpu: 512,
  backendMemory: 1024,
  frontendCpu: 256,
  frontendMemory: 512,
};

test('backend task definition sets AWS_URL to CloudFront domain', () => {
  const app = new cdk.App();
  const network = new NetworkStack(app, 'bp-test-network', {
    env: baseConfig.env,
    config: baseConfig,
    description: 'Test network',
  });

  const data = new DataStack(app, 'bp-test-data', {
    env: baseConfig.env,
    config: baseConfig,
    vpc: network.vpc,
    sgRds: network.sgRds,
    sgRedis: network.sgRedis,
    description: 'Test data',
  });

  const dataAny = data as unknown as { mediaCdnDomain?: string };
  const compute = new ComputeStack(app, 'bp-test-compute', {
    env: baseConfig.env,
    config: baseConfig,
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
    mediaCdnDomain: dataAny.mediaCdnDomain,
    description: 'Test compute',
  } as any);

  const template = Template.fromStack(compute);
  template.hasResourceProperties('AWS::ECS::TaskDefinition', {
    ContainerDefinitions: Match.arrayWith([
      Match.objectLike({
        Name: 'php-fpm',
        Environment: Match.arrayWith([
          Match.objectLike({
            Name: 'AWS_URL',
            Value: Match.objectLike({
              'Fn::Join': Match.arrayWith([
                '',
                Match.arrayWith(['https://']),
              ]),
            }),
          }),
        ]),
      }),
    ]),
  });
});

test('models bucket allows browser presigned uploads via CORS', () => {
  const app = new cdk.App();
  const network = new NetworkStack(app, 'bp-test-network-models-cors', {
    env: baseConfig.env,
    config: baseConfig,
    description: 'Test network',
  });

  const data = new DataStack(app, 'bp-test-data-models-cors', {
    env: baseConfig.env,
    config: baseConfig,
    vpc: network.vpc,
    sgRds: network.sgRds,
    sgRedis: network.sgRedis,
    description: 'Test data',
  });

  const template = Template.fromStack(data);
  template.hasResourceProperties('AWS::S3::Bucket', {
    VersioningConfiguration: {
      Status: 'Enabled',
    },
    CorsConfiguration: {
      CorsRules: Match.arrayWith([
        Match.objectLike({
          AllowedMethods: Match.arrayWith(['PUT', 'POST']),
          AllowedOrigins: Match.arrayWith(['*']),
          AllowedHeaders: Match.arrayWith(['*']),
        }),
      ]),
    },
  });
});
