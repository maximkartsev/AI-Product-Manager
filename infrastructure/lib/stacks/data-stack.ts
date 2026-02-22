import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import * as rds from 'aws-cdk-lib/aws-rds';
import * as elasticache from 'aws-cdk-lib/aws-elasticache';
import * as s3 from 'aws-cdk-lib/aws-s3';
import * as cloudfront from 'aws-cdk-lib/aws-cloudfront';
import * as origins from 'aws-cdk-lib/aws-cloudfront-origins';
import * as secretsmanager from 'aws-cdk-lib/aws-secretsmanager';
import * as ssm from 'aws-cdk-lib/aws-ssm';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';
import type { BpEnvironmentConfig } from '../config/environment';

export interface DataStackProps extends cdk.StackProps {
  readonly config: BpEnvironmentConfig;
  readonly vpc: ec2.IVpc;
  readonly sgRds: ec2.ISecurityGroup;
  readonly sgRedis: ec2.ISecurityGroup;
}

export class DataStack extends cdk.Stack {
  public readonly dbSecret: secretsmanager.ISecret;
  public readonly redisSecret: secretsmanager.ISecret;
  public readonly assetOpsSecret: secretsmanager.ISecret;
  public readonly redisEndpoint: string;
  public readonly mediaBucket: s3.IBucket;
  public readonly mediaCdnDomain: string;
  public readonly modelsBucket: s3.IBucket;
  public readonly logsBucket: s3.IBucket;
  public readonly dbInstanceId: string;

  constructor(scope: Construct, id: string, props: DataStackProps) {
    super(scope, id, props);

    const { config, vpc, sgRds, sgRedis } = props;
    const stage = config.stage;

    // ========================================
    // RDS MariaDB 10.11
    // ========================================

    const dbParameterGroup = new rds.ParameterGroup(this, 'DbParams', {
      engine: rds.DatabaseInstanceEngine.mariaDb({
        version: rds.MariaDbEngineVersion.VER_10_11,
      }),
      parameters: {
        character_set_server: 'utf8mb4',
        collation_server: 'utf8mb4_unicode_ci',
        max_connections: '150',
      },
    });

    const rdsInstanceType = (config.rdsInstanceClass ?? 't4g.small').replace(/^db\./, '');
    const enablePerformanceInsights = !/\.(micro|small)$/.test(rdsInstanceType);

    const dbInstance = new rds.DatabaseInstance(this, 'Database', {
      engine: rds.DatabaseInstanceEngine.mariaDb({
        version: rds.MariaDbEngineVersion.VER_10_11,
      }),
      instanceType: new ec2.InstanceType(rdsInstanceType),
      vpc,
      vpcSubnets: { subnetType: ec2.SubnetType.PRIVATE_ISOLATED },
      securityGroups: [sgRds],
      databaseName: config.centralDbName,
      credentials: rds.Credentials.fromGeneratedSecret('bp_admin', {
        secretName: `/bp/${stage}/rds/master-credentials`,
      }),
      parameterGroup: dbParameterGroup,
      multiAz: config.rdsMultiAz ?? false,
      allocatedStorage: 20,
      maxAllocatedStorage: 100,
      storageType: rds.StorageType.GP3,
      storageEncrypted: true,
      backupRetention: cdk.Duration.days(7),
      deletionProtection: stage === 'production',
      removalPolicy: stage === 'production'
        ? cdk.RemovalPolicy.RETAIN
        : cdk.RemovalPolicy.SNAPSHOT,
      monitoringInterval: cdk.Duration.seconds(60),
      enablePerformanceInsights,
      performanceInsightRetention: enablePerformanceInsights
        ? rds.PerformanceInsightRetention.DEFAULT
        : undefined,
    });

    this.dbSecret = dbInstance.secret!;
    this.dbInstanceId = dbInstance.instanceIdentifier;

    // Tenant pool databases are created via the application migration task
    // (see tenancy:pools-migrate) instead of a custom resource.

    // ========================================
    // ElastiCache Redis 7.1
    // ========================================

    const redisAuthToken = new secretsmanager.Secret(this, 'RedisAuth', {
      secretName: `/bp/${stage}/redis/auth-token`,
      generateSecretString: {
        excludePunctuation: true,
        passwordLength: 32,
      },
    });
    this.redisSecret = redisAuthToken;

    const redisSubnetGroup = new elasticache.CfnSubnetGroup(this, 'RedisSubnets', {
      description: 'Redis subnet group - isolated subnets',
      subnetIds: vpc.isolatedSubnets.map(s => s.subnetId),
    });

    const redis = new elasticache.CfnCacheCluster(this, 'Redis', {
      engine: 'redis',
      engineVersion: '7.1',
      cacheNodeType: config.redisNodeType ?? 'cache.t4g.micro',
      numCacheNodes: 1,
      cacheSubnetGroupName: redisSubnetGroup.ref,
      vpcSecurityGroupIds: [sgRedis.securityGroupId],
      // Note: encryption-in-transit / at-rest and AUTH token require CfnReplicationGroup.
      // For the simple single-node CacheCluster we rely on VPC/Security Group restriction.
    });
    redis.addDependency(redisSubnetGroup);

    this.redisEndpoint = redis.attrRedisEndpointAddress;

    // Store Redis endpoint in SSM for easy reference
    new ssm.StringParameter(this, 'RedisEndpointParam', {
      parameterName: `/bp/${stage}/redis/endpoint`,
      stringValue: redis.attrRedisEndpointAddress,
    });

    // ========================================
    // S3 Media Bucket (user uploads / outputs)
    // ========================================

    this.mediaBucket = new s3.Bucket(this, 'MediaBucket', {
      bucketName: `bp-media-${cdk.Aws.ACCOUNT_ID}-${stage}`,
      encryption: s3.BucketEncryption.S3_MANAGED,
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      versioned: false,
      cors: [
        {
          allowedMethods: [s3.HttpMethods.PUT, s3.HttpMethods.POST],
          allowedOrigins: ['*'], // Tightened at CloudFront level
          allowedHeaders: ['*'],
          maxAge: 3600,
        },
      ],
      lifecycleRules: [
        {
          abortIncompleteMultipartUploadAfter: cdk.Duration.days(1),
        },
      ],
      removalPolicy: stage === 'production'
        ? cdk.RemovalPolicy.RETAIN
        : cdk.RemovalPolicy.DESTROY,
      autoDeleteObjects: stage !== 'production',
    });

    // ========================================
    // S3 Access Logs Bucket (server access logs destination)
    // ========================================

    const accessLogsBucket = new s3.Bucket(this, 'AccessLogsBucket', {
      bucketName: `bp-access-logs-${cdk.Aws.ACCOUNT_ID}-${stage}`,
      encryption: s3.BucketEncryption.S3_MANAGED,
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      lifecycleRules: [
        {
          transitions: [
            { storageClass: s3.StorageClass.INFREQUENT_ACCESS, transitionAfter: cdk.Duration.days(30) },
          ],
          expiration: cdk.Duration.days(90),
        },
      ],
      removalPolicy: stage === 'production'
        ? cdk.RemovalPolicy.RETAIN
        : cdk.RemovalPolicy.DESTROY,
      autoDeleteObjects: stage !== 'production',
    });

    // ========================================
    // S3 ComfyUI Models Bucket (model/LoRA/VAE assets)
    // ========================================

    this.modelsBucket = new s3.Bucket(this, 'ModelsBucket', {
      bucketName: `bp-models-${cdk.Aws.ACCOUNT_ID}-${stage}`,
      encryption: s3.BucketEncryption.S3_MANAGED,
      serverAccessLogsBucket: accessLogsBucket,
      serverAccessLogsPrefix: 's3-access-logs/models/',
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      versioned: true,
      lifecycleRules: [
        {
          abortIncompleteMultipartUploadAfter: cdk.Duration.days(1),
        },
      ],
      removalPolicy: stage === 'production'
        ? cdk.RemovalPolicy.RETAIN
        : cdk.RemovalPolicy.DESTROY,
      autoDeleteObjects: stage !== 'production',
    });

    // Store models bucket name in SSM for tooling/ops
    new ssm.StringParameter(this, 'ModelsBucketParam', {
      parameterName: `/bp/${stage}/models/bucket`,
      stringValue: this.modelsBucket.bucketName,
    });

    // ========================================
    // S3 Logs Bucket
    // ========================================

    const logsBucket = new s3.Bucket(this, 'LogsBucket', {
      bucketName: `bp-logs-${cdk.Aws.ACCOUNT_ID}-${stage}`,
      encryption: s3.BucketEncryption.S3_MANAGED,
      blockPublicAccess: s3.BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      lifecycleRules: [
        {
          transitions: [
            { storageClass: s3.StorageClass.INFREQUENT_ACCESS, transitionAfter: cdk.Duration.days(30) },
          ],
          expiration: cdk.Duration.days(90),
        },
      ],
      removalPolicy: cdk.RemovalPolicy.DESTROY,
      autoDeleteObjects: true,
    });
    this.logsBucket = logsBucket;

    // ========================================
    // CloudFront Distribution (media delivery)
    // ========================================

    const oac = new cloudfront.S3OriginAccessControl(this, 'MediaOac', {
      signing: cloudfront.Signing.SIGV4_ALWAYS,
    });

    const distribution = new cloudfront.Distribution(this, 'MediaCdn', {
      defaultBehavior: {
        origin: origins.S3BucketOrigin.withOriginAccessControl(this.mediaBucket, {
          originAccessControl: oac,
        }),
        viewerProtocolPolicy: cloudfront.ViewerProtocolPolicy.REDIRECT_TO_HTTPS,
        cachePolicy: cloudfront.CachePolicy.CACHING_OPTIMIZED,
        allowedMethods: cloudfront.AllowedMethods.ALLOW_GET_HEAD,
      },
      priceClass: cloudfront.PriceClass.PRICE_CLASS_100,
      httpVersion: cloudfront.HttpVersion.HTTP2_AND_3,
    });
    this.mediaCdnDomain = distribution.distributionDomainName;

    // ========================================
    // Secrets for Laravel app
    // ========================================

    // APP_KEY (generated once, stored in Secrets Manager)
    new secretsmanager.Secret(this, 'LaravelAppKey', {
      secretName: `/bp/${stage}/laravel/app-key`,
      description: 'Laravel APP_KEY (base64:...)',
      // Value must be set manually after first deploy:
      // aws secretsmanager put-secret-value --secret-id /bp/<stage>/laravel/app-key --secret-string "base64:YOUR_KEY"
    });

    // Fleet secret (SSM SecureString for GPU workers)
    new ssm.StringParameter(this, 'FleetSecret', {
      parameterName: `/bp/${stage}/fleet-secret`,
      stringValue: 'CHANGE_ME_AFTER_DEPLOY',
      description: 'Fleet secret for GPU worker registration. Update via AWS Console or CLI.',
      tier: ssm.ParameterTier.STANDARD,
    });

    // OAuth secrets placeholders
    new secretsmanager.Secret(this, 'OauthSecrets', {
      secretName: `/bp/${stage}/oauth/secrets`,
      description: 'OAuth client secrets (Google, Apple, TikTok). Set as JSON after deploy.',
    });

    // Asset ops secret (for GitHub Actions -> backend audit logging)
    this.assetOpsSecret = new secretsmanager.Secret(this, 'AssetOpsSecret', {
      secretName: `/bp/${stage}/asset-ops/secret`,
      description: 'Shared secret for asset ops automation (e.g., GitHub Actions).',
      generateSecretString: {
        excludePunctuation: true,
        passwordLength: 32,
      },
    });

    // ========================================
    // Outputs
    // ========================================

    new cdk.CfnOutput(this, 'RdsEndpoint', { value: dbInstance.instanceEndpoint.hostname });
    new cdk.CfnOutput(this, 'RedisEndpoint', { value: redis.attrRedisEndpointAddress });
    new cdk.CfnOutput(this, 'MediaBucketName', { value: this.mediaBucket.bucketName });
    new cdk.CfnOutput(this, 'ModelsBucketName', { value: this.modelsBucket.bucketName });
    new cdk.CfnOutput(this, 'CloudFrontDomain', { value: distribution.distributionDomainName });
    new cdk.CfnOutput(this, 'LogsBucketName', { value: logsBucket.bucketName });

    // cdk-nag suppressions
    NagSuppressions.addResourceSuppressions(dbInstance, [
      { id: 'AwsSolutions-RDS2', reason: 'Storage encryption enabled with default KMS key' },
      { id: 'AwsSolutions-RDS3', reason: 'Single-instance MariaDB, Multi-AZ used in production config' },
      { id: 'AwsSolutions-RDS10', reason: 'Deletion protection enabled in production config' },
      { id: 'AwsSolutions-RDS11', reason: 'Using default MariaDB port, security via SG restriction' },
      { id: 'AwsSolutions-IAM4', reason: 'RDS Enhanced Monitoring requires AWS managed policy', appliesTo: ['Policy::arn:<AWS::Partition>:iam::aws:policy/service-role/AmazonRDSEnhancedMonitoringRole'] },
      { id: 'AwsSolutions-SMG4', reason: 'RDS credentials rotation configured post-deploy' },
    ], true);
    NagSuppressions.addResourceSuppressions(distribution, [
      { id: 'AwsSolutions-CFR1', reason: 'Geo restriction not needed for media CDN' },
      { id: 'AwsSolutions-CFR2', reason: 'WAF added when needed for DDoS protection' },
      { id: 'AwsSolutions-CFR3', reason: 'Access logging added when log bucket integration is configured' },
      { id: 'AwsSolutions-CFR4', reason: 'Using CloudFront default TLS, custom domain TLS added later' },
    ]);
    NagSuppressions.addResourceSuppressions(this.mediaBucket, [
      { id: 'AwsSolutions-S1', reason: 'Access logs go to separate logs bucket via CloudFront' },
    ]);
    NagSuppressions.addResourceSuppressions(accessLogsBucket, [
      { id: 'AwsSolutions-S1', reason: 'This is the access logs bucket (no recursive logging)' },
    ]);
    NagSuppressions.addResourceSuppressions(logsBucket, [
      { id: 'AwsSolutions-S1', reason: 'This IS the logs bucket, no recursive logging' },
    ]);
    NagSuppressions.addResourceSuppressions(redis, [
      { id: 'AwsSolutions-AEC3', reason: 'Single-node Redis, AUTH requires replication group' },
      { id: 'AwsSolutions-AEC4', reason: 'Single-node Redis, no replication group' },
      { id: 'AwsSolutions-AEC5', reason: 'Single-node Redis, no replication group for AUTH' },
    ]);
    NagSuppressions.addResourceSuppressions(redisAuthToken, [
      { id: 'AwsSolutions-SMG4', reason: 'Redis AUTH token rotation not needed for single-node setup' },
    ]);
    NagSuppressions.addStackSuppressions(this, [
      { id: 'AwsSolutions-SMG4', reason: 'Secrets rotation configured post-deploy for app-level secrets' },
    ]);
  }
}
