import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as ecs from 'aws-cdk-lib/aws-ecs';
import * as elbv2 from 'aws-cdk-lib/aws-elasticloadbalancingv2';
import * as ecr from 'aws-cdk-lib/aws-ecr';
import * as s3 from 'aws-cdk-lib/aws-s3';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as secretsmanager from 'aws-cdk-lib/aws-secretsmanager';
import * as ssm from 'aws-cdk-lib/aws-ssm';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';
import type { BpEnvironmentConfig } from '../config/environment';

export interface ComputeStackProps extends cdk.StackProps {
  readonly config: BpEnvironmentConfig;
  readonly vpc: ec2.IVpc;
  readonly sgAlb: ec2.ISecurityGroup;
  readonly sgBackend: ec2.ISecurityGroup;
  readonly sgFrontend: ec2.ISecurityGroup;
  readonly dbSecret: secretsmanager.ISecret;
  readonly redisSecret: secretsmanager.ISecret;
  readonly assetOpsSecret: secretsmanager.ISecret;
  readonly redisEndpoint: string;
  readonly mediaBucket: s3.IBucket;
  readonly mediaCdnDomain: string;
  readonly modelsBucket: s3.IBucket;
  readonly logsBucket: s3.IBucket;
}

export class ComputeStack extends cdk.Stack {
  public readonly cluster: ecs.ICluster;
  public readonly albFullName: string;
  public tgBackendFullName: string;
  public backendServiceName: string;
  public frontendServiceName: string;
  public apiBaseUrl: string;
  private readonly backendRepo: ecr.IRepository;
  private readonly frontendRepo: ecr.IRepository;
  private readonly appKeySecret: secretsmanager.ISecret;
  private readonly oauthSecrets: secretsmanager.ISecret;
  private readonly fleetSecretStagingParam: ssm.IStringParameter;
  private readonly fleetSecretProductionParam: ssm.IStringParameter;
  private readonly assetOpsSecret: secretsmanager.ISecret;
  private readonly mediaCdnDomain: string;
  private readonly modelsBucket: s3.IBucket;
  private readonly logsBucket: s3.IBucket;
  private readonly logRetention: logs.RetentionDays;

  constructor(scope: Construct, id: string, props: ComputeStackProps) {
    super(scope, id, props);

    const { config, vpc, sgAlb, sgBackend, sgFrontend, dbSecret, redisSecret, assetOpsSecret, redisEndpoint, mediaBucket, mediaCdnDomain, modelsBucket, logsBucket } = props;
    this.backendRepo = ecr.Repository.fromRepositoryName(this, 'BackendRepo', 'bp-backend');
    this.frontendRepo = ecr.Repository.fromRepositoryName(this, 'FrontendRepo', 'bp-frontend');
    this.logRetention = logs.RetentionDays.ONE_MONTH;
    this.appKeySecret = secretsmanager.Secret.fromSecretNameV2(this, 'LaravelAppKey', '/bp/laravel/app-key');
    this.oauthSecrets = secretsmanager.Secret.fromSecretNameV2(this, 'OauthSecrets', '/bp/oauth/secrets');
    this.fleetSecretStagingParam = ssm.StringParameter.fromStringParameterName(this, 'FleetSecretStaging', '/bp/fleets/staging/fleet-secret');
    this.fleetSecretProductionParam = ssm.StringParameter.fromStringParameterName(this, 'FleetSecretProduction', '/bp/fleets/production/fleet-secret');
    this.assetOpsSecret = assetOpsSecret;
    this.mediaCdnDomain = mediaCdnDomain;
    this.modelsBucket = modelsBucket;
    this.logsBucket = logsBucket;

    // ========================================
    // ECS Cluster
    // ========================================

    const cluster = new ecs.Cluster(this, 'Cluster', {
      vpc,
      clusterName: 'bp',
      containerInsights: true,
      enableFargateCapacityProviders: true,
    });
    this.cluster = cluster;

    // ========================================
    // ALB
    // ========================================

    const alb = new elbv2.ApplicationLoadBalancer(this, 'Alb', {
      vpc,
      internetFacing: true,
      securityGroup: sgAlb,
      vpcSubnets: { subnetType: ec2.SubnetType.PUBLIC },
    });
    this.albFullName = alb.loadBalancerFullName;

    // HTTP -> HTTPS redirect (or HTTP listener if no cert)
    const hasCert = !!config.certificateArn;

    // Always create a single port-80 listener (stable logical ID).
    // When HTTPS is enabled, it redirects to 443. Otherwise, it serves the app over HTTP.
    const httpListener = alb.addListener('Http', {
      port: 80,
      protocol: elbv2.ApplicationProtocol.HTTP,
      defaultAction: hasCert
        ? elbv2.ListenerAction.redirect({
          protocol: 'HTTPS',
          port: '443',
          permanent: true,
        })
        : elbv2.ListenerAction.fixedResponse(404, {
          contentType: 'text/plain',
          messageBody: 'Not Found',
        }),
    });

    // Compute the externally-visible base URL before creating task definitions
    // (Laravel & Next.js containers read it from env vars).
    this.apiBaseUrl = hasCert
      ? `https://${config.domainName}`
      : `http://${alb.loadBalancerDnsName}`;

    const { backendTg, frontendTg } = this.setupServicesAndTargetGroups(
      httpListener,
      cluster,
      config,
      vpc,
      sgBackend,
      sgFrontend,
      dbSecret,
      redisSecret,
      redisEndpoint,
      mediaBucket,
    );

    const attachToListener = (listener: elbv2.IApplicationListener) => {
      listener.addTargetGroups('BackendRule', {
        priority: 10,
        conditions: [
          elbv2.ListenerCondition.pathPatterns(['/api/*', '/sanctum/*', '/up']),
        ],
        targetGroups: [backendTg],
      });

      listener.addTargetGroups('FrontendRule', {
        priority: 50,
        conditions: [elbv2.ListenerCondition.pathPatterns(['/*'])],
        targetGroups: [frontendTg],
      });
    };

    if (hasCert) {
      // HTTPS listener (rules live here when cert is configured).
      const httpsListener = alb.addListener('Https', {
        port: 443,
        protocol: elbv2.ApplicationProtocol.HTTPS,
        certificates: [
          elbv2.ListenerCertificate.fromArn(config.certificateArn!),
        ],
        defaultAction: elbv2.ListenerAction.fixedResponse(404, {
          contentType: 'text/plain',
          messageBody: 'Not Found',
        }),
      });

      attachToListener(httpsListener);
    } else {
      attachToListener(httpListener);
    }

    // ========================================
    // Outputs
    // ========================================

    new cdk.CfnOutput(this, 'AlbDns', { value: alb.loadBalancerDnsName });
    new cdk.CfnOutput(this, 'ApiBaseUrl', { value: this.apiBaseUrl });
    new cdk.CfnOutput(this, 'ClusterName', { value: cluster.clusterName });

    NagSuppressions.addResourceSuppressions(alb, [
      { id: 'AwsSolutions-ELB2', reason: 'ALB access logs added when logs bucket is configured' },
      { id: 'AwsSolutions-EC23', reason: 'ALB is internet-facing by design' },
    ]);
  }

  private setupServicesAndTargetGroups(
    tgScope: Construct,
    cluster: ecs.ICluster,
    config: BpEnvironmentConfig,
    vpc: ec2.IVpc,
    sgBackend: ec2.ISecurityGroup,
    sgFrontend: ec2.ISecurityGroup,
    dbSecret: secretsmanager.ISecret,
    redisSecret: secretsmanager.ISecret,
    redisEndpoint: string,
    mediaBucket: s3.IBucket,
  ): { backendTg: elbv2.ApplicationTargetGroup; frontendTg: elbv2.ApplicationTargetGroup } {
    // ========================================
    // Backend Task Definition
    // ========================================

    const backendTaskDef = new ecs.FargateTaskDefinition(this, 'BackendTask', {
      family: 'bp-backend',
      cpu: config.backendCpu ?? 512,
      memoryLimitMiB: config.backendMemory ?? 1024,
      runtimePlatform: {
        cpuArchitecture: ecs.CpuArchitecture.ARM64,
        operatingSystemFamily: ecs.OperatingSystemFamily.LINUX,
      },
    });

    // Grant task role access to S3 buckets
    mediaBucket.grantReadWrite(backendTaskDef.taskRole);
    this.modelsBucket.grantReadWrite(backendTaskDef.taskRole);
    this.logsBucket.grantRead(backendTaskDef.taskRole);

    // Grant CloudWatch PutMetricData for workers:publish-metrics
    backendTaskDef.taskRole.addToPrincipalPolicy(new iam.PolicyStatement({
      actions: ['cloudwatch:PutMetricData'],
      resources: ['*'],
      conditions: {
        StringEquals: { 'cloudwatch:namespace': 'ComfyUI/Workers' },
      },
    }));

    // Allow backend to update active asset bundle pointer in SSM
    backendTaskDef.taskRole.addToPrincipalPolicy(new iam.PolicyStatement({
      actions: ['ssm:PutParameter', 'ssm:GetParameter'],
      resources: [
        `arn:aws:ssm:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:parameter/bp/fleets/*/*/active_bundle`,
        `arn:aws:ssm:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:parameter/bp/fleets/*/*/desired_config`,
      ],
    }));

    const backendLogGroup = new logs.LogGroup(this, 'BackendLogs', {
      logGroupName: '/ecs/bp-backend',
      retention: this.logRetention,
      removalPolicy: cdk.RemovalPolicy.DESTROY,
    });

    // Container 1: Nginx (reverse proxy to PHP-FPM)
    const nginxContainer = backendTaskDef.addContainer('nginx', {
      image: ecs.ContainerImage.fromEcrRepository(this.backendRepo, 'nginx-latest'),
      essential: true,
      portMappings: [{ containerPort: 80, protocol: ecs.Protocol.TCP }],
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'nginx', logGroup: backendLogGroup }),
      healthCheck: {
        command: ['CMD-SHELL', 'wget -q -O /dev/null http://127.0.0.1/up || exit 1'],
        interval: cdk.Duration.seconds(30),
        timeout: cdk.Duration.seconds(5),
        retries: 3,
        startPeriod: cdk.Duration.seconds(60),
      },
    });

    const applePrivateKeyPath = '/var/www/html/storage/keys/Apple_AuthKey.p8';
    const buildPhpBootstrapCommand = (processCommand: string): string => [
      // Note: entrypoint is /bin/sh, so avoid bash-only options like pipefail.
      'set -eu',
      'umask 077',
      'mkdir -p /var/www/html/storage/keys',
      'mkdir -p /var/www/html/storage/framework',
      'mkdir -p /var/www/html/storage/framework/cache',
      'mkdir -p /var/www/html/storage/framework/cache/data',
      'mkdir -p /var/www/html/storage/framework/sessions',
      'mkdir -p /var/www/html/storage/framework/views',
      'mkdir -p /var/www/html/storage/framework/testing',
      'mkdir -p /var/www/html/storage/logs',
      'mkdir -p /var/www/html/bootstrap/cache',
      'if [ -n "${APPLE_PRIVATE_KEY_P8_B64:-}" ]; then',
      `  printf '%s' "$APPLE_PRIVATE_KEY_P8_B64" | base64 -d > ${applePrivateKeyPath}`,
      'fi',
      `exec ${processCommand}`,
    ].join('\n');

    // Shared environment variables for all PHP containers
    const phpEnvironment: Record<string, string> = {
      APP_ENV: 'production',
      APP_DEBUG: 'false',
      APP_URL: this.apiBaseUrl || `https://app.example.com`,
      FRONTEND_URL: this.apiBaseUrl || `https://app.example.com`,
      APPLE_PRIVATE_KEY: applePrivateKeyPath,
      LOG_CHANNEL: 'stderr',
      DB_CONNECTION: 'central',
      CENTRAL_DB_DRIVER: 'mariadb',
      CENTRAL_DB_PORT: '3306',
      CENTRAL_DB_DATABASE: config.centralDbName,
      TENANT_POOL_1_DB_DRIVER: 'mariadb',
      TENANT_POOL_1_DB_DATABASE: config.tenantPoolDbNames[0] ?? 'tenant_pool_1',
      TENANT_POOL_2_DB_DRIVER: 'mariadb',
      TENANT_POOL_2_DB_DATABASE: config.tenantPoolDbNames[1] ?? 'tenant_pool_2',
      REDIS_HOST: redisEndpoint,
      REDIS_PORT: '6379',
      CACHE_STORE: 'redis',
      SESSION_DRIVER: 'redis',
      QUEUE_CONNECTION: 'database',
      FILESYSTEM_DISK: 's3',
      AWS_DEFAULT_REGION: cdk.Aws.REGION,
      AWS_URL: `https://${this.mediaCdnDomain}`,
      COMFYUI_AWS_REGION: cdk.Aws.REGION,
      AWS_BUCKET: mediaBucket.bucketName,
      COMFYUI_MODELS_BUCKET: this.modelsBucket.bucketName,
      COMFYUI_MODELS_DISK: 'comfyui_models',
      COMFYUI_LOGS_BUCKET: this.logsBucket.bucketName,
      COMFYUI_LOGS_DISK: 'comfyui_logs',
    };

    const phpSecrets: Record<string, ecs.Secret> = {
      APP_KEY: ecs.Secret.fromSecretsManager(this.appKeySecret),
      CENTRAL_DB_HOST: ecs.Secret.fromSecretsManager(dbSecret, 'host'),
      CENTRAL_DB_USERNAME: ecs.Secret.fromSecretsManager(dbSecret, 'username'),
      CENTRAL_DB_PASSWORD: ecs.Secret.fromSecretsManager(dbSecret, 'password'),
      TENANT_POOL_1_DB_HOST: ecs.Secret.fromSecretsManager(dbSecret, 'host'),
      TENANT_POOL_1_DB_USERNAME: ecs.Secret.fromSecretsManager(dbSecret, 'username'),
      TENANT_POOL_1_DB_PASSWORD: ecs.Secret.fromSecretsManager(dbSecret, 'password'),
      TENANT_POOL_2_DB_HOST: ecs.Secret.fromSecretsManager(dbSecret, 'host'),
      TENANT_POOL_2_DB_USERNAME: ecs.Secret.fromSecretsManager(dbSecret, 'username'),
      TENANT_POOL_2_DB_PASSWORD: ecs.Secret.fromSecretsManager(dbSecret, 'password'),
      COMFYUI_ASSET_OPS_SECRET: ecs.Secret.fromSecretsManager(this.assetOpsSecret),
      GOOGLE_CLIENT_ID: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'google_client_id'),
      GOOGLE_CLIENT_SECRET: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'google_client_secret'),
      TIKTOK_CLIENT_ID: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'tiktok_client_id'),
      TIKTOK_CLIENT_SECRET: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'tiktok_client_secret'),
      APPLE_CLIENT_ID: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'apple_client_id'),
      APPLE_CLIENT_SECRET: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'apple_client_secret'),
      APPLE_KEY_ID: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'apple_key_id'),
      APPLE_TEAM_ID: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'apple_team_id'),
      APPLE_PRIVATE_KEY_P8_B64: ecs.Secret.fromSecretsManager(this.oauthSecrets, 'apple_private_key_p8_b64'),
      COMFYUI_FLEET_SECRET_STAGING: ecs.Secret.fromSsmParameter(this.fleetSecretStagingParam),
      COMFYUI_FLEET_SECRET_PRODUCTION: ecs.Secret.fromSsmParameter(this.fleetSecretProductionParam),
    };

    // Container 2: PHP-FPM (Laravel app)
    backendTaskDef.addContainer('php-fpm', {
      image: ecs.ContainerImage.fromEcrRepository(this.backendRepo, 'php-latest'),
      essential: true,
      entryPoint: ['/bin/sh', '-lc'],
      command: [buildPhpBootstrapCommand('/usr/local/sbin/php-fpm')],
      environment: phpEnvironment,
      secrets: phpSecrets,
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'php', logGroup: backendLogGroup }),
    });

    // Container 3: Scheduler sidecar
    backendTaskDef.addContainer('scheduler', {
      image: ecs.ContainerImage.fromEcrRepository(this.backendRepo, 'php-latest'),
      essential: false,
      entryPoint: ['/bin/sh', '-lc'],
      command: [buildPhpBootstrapCommand('/usr/local/bin/php artisan schedule:work')],
      environment: phpEnvironment,
      secrets: phpSecrets,
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'scheduler', logGroup: backendLogGroup }),
    });

    // Container 4: Queue worker sidecar
    backendTaskDef.addContainer('queue-worker', {
      image: ecs.ContainerImage.fromEcrRepository(this.backendRepo, 'php-latest'),
      essential: false,
      entryPoint: ['/bin/sh', '-lc'],
      command: [buildPhpBootstrapCommand('/usr/local/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600')],
      environment: phpEnvironment,
      secrets: phpSecrets,
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'queue', logGroup: backendLogGroup }),
    });

    // ========================================
    // Backend ECS Service
    // ========================================

    const backendService = new ecs.FargateService(this, 'BackendService', {
      serviceName: 'bp-backend',
      cluster,
      taskDefinition: backendTaskDef,
      desiredCount: 1,
      // Allow app dependencies/migrations to settle before ALB marks task unhealthy.
      healthCheckGracePeriod: cdk.Duration.seconds(300),
      securityGroups: [sgBackend],
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      capacityProviderStrategies: [
        { capacityProvider: 'FARGATE', base: 1, weight: 1 },
        { capacityProvider: 'FARGATE_SPOT', weight: 3 },
      ],
      circuitBreaker: { rollback: true },
      enableExecuteCommand: true,
    });
    this.backendServiceName = backendService.serviceName;

    // Backend auto-scaling
    const backendScaling = backendService.autoScaleTaskCount({ minCapacity: 1, maxCapacity: 4 });
    backendScaling.scaleOnCpuUtilization('CpuScaling', { targetUtilizationPercent: 70 });

    // Backend target group
    // Keep the construct ID stable across HTTP/HTTPS modes because bp-monitoring imports the
    // TargetGroupFullName dimension for UnHealthyHostCount alarms.
    const backendTg = new elbv2.ApplicationTargetGroup(tgScope, 'BackendTargetGroup', {
      vpc,
      protocol: elbv2.ApplicationProtocol.HTTP,
      port: 80,
      targetType: elbv2.TargetType.IP,
      targets: [backendService],
      healthCheck: {
        path: '/up',
        interval: cdk.Duration.seconds(30),
        healthyThresholdCount: 2,
        unhealthyThresholdCount: 3,
      },
    });
    this.tgBackendFullName = backendTg.targetGroupFullName;

    // ========================================
    // Frontend Task Definition
    // ========================================

    const frontendTaskDef = new ecs.FargateTaskDefinition(this, 'FrontendTask', {
      family: 'bp-frontend',
      cpu: config.frontendCpu ?? 256,
      memoryLimitMiB: config.frontendMemory ?? 512,
      runtimePlatform: {
        cpuArchitecture: ecs.CpuArchitecture.ARM64,
        operatingSystemFamily: ecs.OperatingSystemFamily.LINUX,
      },
    });

    const frontendLogGroup = new logs.LogGroup(this, 'FrontendLogs', {
      logGroupName: '/ecs/bp-frontend',
      retention: this.logRetention,
      removalPolicy: cdk.RemovalPolicy.DESTROY,
    });

    frontendTaskDef.addContainer('nextjs', {
      image: ecs.ContainerImage.fromEcrRepository(this.frontendRepo, 'latest'),
      essential: true,
      portMappings: [{ containerPort: 3000, protocol: ecs.Protocol.TCP }],
      environment: {
        NODE_ENV: 'production',
        // The frontend expects the backend API base to include `/api`.
        NEXT_PUBLIC_API_BASE_URL: `${this.apiBaseUrl}/api`,
        NEXT_PUBLIC_API_URL: `${this.apiBaseUrl}/api`,
      },
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'nextjs', logGroup: frontendLogGroup }),
      healthCheck: {
        command: [
          'CMD',
          'node',
          '-e',
          'const os=require("os");const http=require("http");const nets=os.networkInterfaces();const addrs=Object.values(nets).flat().filter(n=>n&&n.family==="IPv4"&&!n.internal&&!n.address.startsWith("169.254."));const ip=(addrs.find(n=>n.address.startsWith("10."))||addrs[0])?.address;if(!ip){process.exit(1);}const req=http.get("http://"+ip+":3000/",res=>{process.exit(res.statusCode&&res.statusCode<400?0:1);});req.on("error",()=>process.exit(1));req.setTimeout(2000,()=>{req.destroy();process.exit(1);});',
        ],
        interval: cdk.Duration.seconds(30),
        timeout: cdk.Duration.seconds(10),
        retries: 3,
        startPeriod: cdk.Duration.seconds(60),
      },
    });

    // ========================================
    // Frontend ECS Service
    // ========================================

    const frontendService = new ecs.FargateService(this, 'FrontendService', {
      serviceName: 'bp-frontend',
      cluster,
      taskDefinition: frontendTaskDef,
      desiredCount: 1,
      healthCheckGracePeriod: cdk.Duration.seconds(120),
      securityGroups: [sgFrontend],
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS },
      capacityProviderStrategies: [
        { capacityProvider: 'FARGATE_SPOT', weight: 1 },
      ],
      circuitBreaker: { rollback: true },
      enableExecuteCommand: true,
    });
    this.frontendServiceName = frontendService.serviceName;

    // Frontend auto-scaling
    const frontendScaling = frontendService.autoScaleTaskCount({ minCapacity: 1, maxCapacity: 3 });
    frontendScaling.scaleOnCpuUtilization('CpuScaling', { targetUtilizationPercent: 70 });

    // Frontend target group (default / catch-all)
    const frontendTg = new elbv2.ApplicationTargetGroup(tgScope, 'FrontendTargetGroup', {
      vpc,
      protocol: elbv2.ApplicationProtocol.HTTP,
      port: 3000,
      targetType: elbv2.TargetType.IP,
      targets: [frontendService],
      healthCheck: {
        path: '/',
        interval: cdk.Duration.seconds(30),
        timeout: cdk.Duration.seconds(10),
        healthyHttpCodes: '200-399',
        healthyThresholdCount: 2,
        unhealthyThresholdCount: 3,
      },
    });

    // cdk-nag suppressions
    NagSuppressions.addResourceSuppressions(backendTaskDef, [
      { id: 'AwsSolutions-IAM5', reason: 'S3 and CloudWatch wildcard scoped by bucket/namespace' },
      { id: 'AwsSolutions-ECS2', reason: 'Non-secret env vars are acceptable in task definition' },
    ], true);
    NagSuppressions.addResourceSuppressions(frontendTaskDef, [
      { id: 'AwsSolutions-ECS2', reason: 'Frontend only has non-secret env vars' },
      { id: 'AwsSolutions-IAM5', reason: 'ECS Exec requires wildcard permissions for SSM' },
    ], true);
    NagSuppressions.addResourceSuppressions([backendService, frontendService], [
      { id: 'AwsSolutions-ECS4', reason: 'Using CloudWatch Container Insights at cluster level' },
    ], true);

    return { backendTg, frontendTg };
  }
}
