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
  private readonly fleetSecretParam: ssm.IStringParameter;
  private readonly assetOpsSecret: secretsmanager.ISecret;
  private readonly modelsBucket: s3.IBucket;
  private readonly logsBucket: s3.IBucket;
  private readonly logRetention: logs.RetentionDays;

  constructor(scope: Construct, id: string, props: ComputeStackProps) {
    super(scope, id, props);

    const { config, vpc, sgAlb, sgBackend, sgFrontend, dbSecret, redisSecret, assetOpsSecret, redisEndpoint, mediaBucket, modelsBucket, logsBucket } = props;
    const stage = config.stage;
    this.backendRepo = ecr.Repository.fromRepositoryName(this, 'BackendRepo', `bp-backend-${stage}`);
    this.frontendRepo = ecr.Repository.fromRepositoryName(this, 'FrontendRepo', `bp-frontend-${stage}`);
    this.logRetention = stage === 'production' ? logs.RetentionDays.ONE_MONTH : logs.RetentionDays.ONE_WEEK;
    this.appKeySecret = secretsmanager.Secret.fromSecretNameV2(this, 'LaravelAppKey', `/bp/${stage}/laravel/app-key`);
    this.fleetSecretParam = ssm.StringParameter.fromStringParameterName(this, 'FleetSecret', `/bp/${stage}/fleet-secret`);
    this.assetOpsSecret = assetOpsSecret;
    this.modelsBucket = modelsBucket;
    this.logsBucket = logsBucket;

    // ========================================
    // ECS Cluster
    // ========================================

    const cluster = new ecs.Cluster(this, 'Cluster', {
      vpc,
      clusterName: `bp-${stage}`,
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

    if (hasCert) {
      // HTTPS listener
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

      // HTTP redirect to HTTPS
      alb.addListener('HttpRedirect', {
        port: 80,
        defaultAction: elbv2.ListenerAction.redirect({
          protocol: 'HTTPS',
          port: '443',
          permanent: true,
        }),
      });

      this.apiBaseUrl = `https://${config.domainName}`;
      this.setupTargetGroups(httpsListener, cluster, config, vpc, sgBackend, sgFrontend, dbSecret, redisSecret, redisEndpoint, mediaBucket);
    } else {
      // HTTP-only listener (no cert yet)
      const httpListener = alb.addListener('Http', {
        port: 80,
        protocol: elbv2.ApplicationProtocol.HTTP,
        defaultAction: elbv2.ListenerAction.fixedResponse(404, {
          contentType: 'text/plain',
          messageBody: 'Not Found',
        }),
      });

      this.apiBaseUrl = `http://${alb.loadBalancerDnsName}`;
      this.setupTargetGroups(httpListener, cluster, config, vpc, sgBackend, sgFrontend, dbSecret, redisSecret, redisEndpoint, mediaBucket);
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

  private setupTargetGroups(
    listener: elbv2.IApplicationListener,
    cluster: ecs.ICluster,
    config: BpEnvironmentConfig,
    vpc: ec2.IVpc,
    sgBackend: ec2.ISecurityGroup,
    sgFrontend: ec2.ISecurityGroup,
    dbSecret: secretsmanager.ISecret,
    redisSecret: secretsmanager.ISecret,
    redisEndpoint: string,
    mediaBucket: s3.IBucket,
  ) {
    const stage = config.stage;

    // ========================================
    // Backend Task Definition
    // ========================================

    const backendTaskDef = new ecs.FargateTaskDefinition(this, 'BackendTask', {
      family: `bp-${stage}-backend`,
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
        `arn:aws:ssm:${cdk.Aws.REGION}:${cdk.Aws.ACCOUNT_ID}:parameter/bp/${stage}/assets/*/active_bundle`,
      ],
    }));

    const backendLogGroup = new logs.LogGroup(this, 'BackendLogs', {
      logGroupName: `/ecs/bp-backend-${stage}`,
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
        command: ['CMD-SHELL', 'curl -f http://localhost/up || exit 1'],
        interval: cdk.Duration.seconds(30),
        timeout: cdk.Duration.seconds(5),
        retries: 3,
        startPeriod: cdk.Duration.seconds(60),
      },
    });

    // Shared environment variables for all PHP containers
    const phpEnvironment: Record<string, string> = {
      APP_ENV: stage,
      APP_DEBUG: stage === 'production' ? 'false' : 'true',
      APP_URL: this.apiBaseUrl || `https://app.example.com`,
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
      COMFYUI_FLEET_SECRET: ecs.Secret.fromSsmParameter(this.fleetSecretParam),
      COMFYUI_ASSET_OPS_SECRET: ecs.Secret.fromSecretsManager(this.assetOpsSecret),
    };

    // Container 2: PHP-FPM (Laravel app)
    backendTaskDef.addContainer('php-fpm', {
      image: ecs.ContainerImage.fromEcrRepository(this.backendRepo, 'php-latest'),
      essential: true,
      environment: phpEnvironment,
      secrets: phpSecrets,
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'php', logGroup: backendLogGroup }),
    });

    // Container 3: Scheduler sidecar
    backendTaskDef.addContainer('scheduler', {
      image: ecs.ContainerImage.fromEcrRepository(this.backendRepo, 'php-latest'),
      essential: false,
      command: ['php', 'artisan', 'schedule:work'],
      environment: phpEnvironment,
      secrets: phpSecrets,
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'scheduler', logGroup: backendLogGroup }),
    });

    // Container 4: Queue worker sidecar
    backendTaskDef.addContainer('queue-worker', {
      image: ecs.ContainerImage.fromEcrRepository(this.backendRepo, 'php-latest'),
      essential: false,
      command: ['php', 'artisan', 'queue:work', '--sleep=3', '--tries=3', '--max-time=3600'],
      environment: phpEnvironment,
      secrets: phpSecrets,
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'queue', logGroup: backendLogGroup }),
    });

    // ========================================
    // Backend ECS Service
    // ========================================

    const backendService = new ecs.FargateService(this, 'BackendService', {
      serviceName: `bp-${stage}-backend`,
      cluster,
      taskDefinition: backendTaskDef,
      desiredCount: 1,
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
    const backendTg = listener.addTargets('BackendTarget', {
      priority: 10,
      conditions: [
        elbv2.ListenerCondition.pathPatterns(['/api/*', '/sanctum/*', '/up']),
      ],
      port: 80,
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
      family: `bp-${stage}-frontend`,
      cpu: config.frontendCpu ?? 256,
      memoryLimitMiB: config.frontendMemory ?? 512,
      runtimePlatform: {
        cpuArchitecture: ecs.CpuArchitecture.ARM64,
        operatingSystemFamily: ecs.OperatingSystemFamily.LINUX,
      },
    });

    const frontendLogGroup = new logs.LogGroup(this, 'FrontendLogs', {
      logGroupName: `/ecs/bp-frontend-${stage}`,
      retention: this.logRetention,
      removalPolicy: cdk.RemovalPolicy.DESTROY,
    });

    frontendTaskDef.addContainer('nextjs', {
      image: ecs.ContainerImage.fromEcrRepository(this.frontendRepo, 'latest'),
      essential: true,
      portMappings: [{ containerPort: 3000, protocol: ecs.Protocol.TCP }],
      environment: {
        NODE_ENV: 'production',
        NEXT_PUBLIC_API_URL: this.apiBaseUrl || '',
      },
      logging: ecs.LogDrivers.awsLogs({ streamPrefix: 'nextjs', logGroup: frontendLogGroup }),
      healthCheck: {
        command: ['CMD-SHELL', 'wget -q -O /dev/null http://localhost:3000/ || exit 1'],
        interval: cdk.Duration.seconds(30),
        timeout: cdk.Duration.seconds(5),
        retries: 3,
        startPeriod: cdk.Duration.seconds(30),
      },
    });

    // ========================================
    // Frontend ECS Service
    // ========================================

    const frontendService = new ecs.FargateService(this, 'FrontendService', {
      serviceName: `bp-${stage}-frontend`,
      cluster,
      taskDefinition: frontendTaskDef,
      desiredCount: 1,
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
    listener.addTargets('FrontendTarget', {
      priority: 50,
      conditions: [elbv2.ListenerCondition.pathPatterns(['/*'])],
      port: 3000,
      protocol: elbv2.ApplicationProtocol.HTTP,
      targets: [frontendService],
      healthCheck: {
        path: '/',
        interval: cdk.Duration.seconds(30),
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
  }
}
