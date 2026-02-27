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

test('compute stack injects oauth secrets and apple key bootstrap into backend php containers', () => {
  const app = new cdk.App();
  const network = new NetworkStack(app, 'bp-test-network-oauth-secrets', {
    env: baseConfig.env,
    config: baseConfig,
    description: 'Test network',
  });

  const data = new DataStack(app, 'bp-test-data-oauth-secrets', {
    env: baseConfig.env,
    config: baseConfig,
    vpc: network.vpc,
    sgRds: network.sgRds,
    sgRedis: network.sgRedis,
    description: 'Test data',
  });

  const dataAny = data as unknown as { mediaCdnDomain?: string };
  const compute = new ComputeStack(app, 'bp-test-compute-oauth-secrets', {
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
        EntryPoint: ['/bin/sh', '-lc'],
        Command: Match.arrayWith([Match.stringLikeRegexp('Apple_AuthKey\\.p8')]),
        Environment: Match.arrayWith([
          Match.objectLike({ Name: 'FRONTEND_URL' }),
          Match.objectLike({ Name: 'APPLE_PRIVATE_KEY', Value: '/var/www/html/storage/keys/Apple_AuthKey.p8' }),
        ]),
        Secrets: Match.arrayWith([
          Match.objectLike({ Name: 'GOOGLE_CLIENT_ID' }),
          Match.objectLike({ Name: 'GOOGLE_CLIENT_SECRET' }),
          Match.objectLike({ Name: 'TIKTOK_CLIENT_ID' }),
          Match.objectLike({ Name: 'TIKTOK_CLIENT_SECRET' }),
          Match.objectLike({ Name: 'APPLE_CLIENT_ID' }),
          Match.objectLike({ Name: 'APPLE_KEY_ID' }),
          Match.objectLike({ Name: 'APPLE_TEAM_ID' }),
          Match.objectLike({ Name: 'APPLE_PRIVATE_KEY_P8_B64' }),
          Match.objectLike({ Name: 'COMFYUI_FLEET_SECRET_STAGING' }),
        ]),
      }),
      Match.objectLike({
        Name: 'scheduler',
        EntryPoint: ['/bin/sh', '-lc'],
        Command: Match.arrayWith([Match.stringLikeRegexp('schedule:work')]),
        Environment: Match.arrayWith([
          Match.objectLike({ Name: 'APPLE_PRIVATE_KEY', Value: '/var/www/html/storage/keys/Apple_AuthKey.p8' }),
        ]),
        Secrets: Match.arrayWith([
          Match.objectLike({ Name: 'APPLE_PRIVATE_KEY_P8_B64' }),
        ]),
      }),
      Match.objectLike({
        Name: 'queue-worker',
        EntryPoint: ['/bin/sh', '-lc'],
        Command: Match.arrayWith([Match.stringLikeRegexp('queue:work')]),
      }),
    ]),
  });
});
