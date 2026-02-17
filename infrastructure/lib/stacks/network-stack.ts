import * as cdk from 'aws-cdk-lib';
import * as ec2 from 'aws-cdk-lib/aws-ec2';
import { Construct } from 'constructs';
import { NagSuppressions } from 'cdk-nag';
import type { BpEnvironmentConfig } from '../config/environment';

export interface NetworkStackProps extends cdk.StackProps {
  readonly config: BpEnvironmentConfig;
}

export class NetworkStack extends cdk.Stack {
  public readonly vpc: ec2.IVpc;
  public readonly sgAlb: ec2.ISecurityGroup;
  public readonly sgBackend: ec2.ISecurityGroup;
  public readonly sgFrontend: ec2.ISecurityGroup;
  public readonly sgRds: ec2.ISecurityGroup;
  public readonly sgRedis: ec2.ISecurityGroup;
  public readonly sgGpuWorkers: ec2.ISecurityGroup;
  public readonly natGatewayIds: string[];

  constructor(scope: Construct, id: string, props: NetworkStackProps) {
    super(scope, id, props);

    const { config } = props;
    const natGateways = config.natGateways ?? 1;

    // --- VPC ---
    const vpc = new ec2.Vpc(this, 'Vpc', {
      ipAddresses: ec2.IpAddresses.cidr('10.0.0.0/16'),
      maxAzs: 2,
      natGateways,
      subnetConfiguration: [
        {
          cidrMask: 24,
          name: 'public',
          subnetType: ec2.SubnetType.PUBLIC,
        },
        {
          cidrMask: 22,
          name: 'private',
          subnetType: ec2.SubnetType.PRIVATE_WITH_EGRESS,
        },
        {
          cidrMask: 24,
          name: 'isolated',
          subnetType: ec2.SubnetType.PRIVATE_ISOLATED,
        },
      ],
    });
    this.vpc = vpc;
    this.natGatewayIds = vpc.node
      .findAll()
      .filter((child): child is ec2.CfnNatGateway => child instanceof ec2.CfnNatGateway)
      .map(child => child.ref);

    // S3 Gateway Endpoint (free — saves NAT transfer costs)
    vpc.addGatewayEndpoint('S3Endpoint', {
      service: ec2.GatewayVpcEndpointAwsService.S3,
    });

    // --- Security Groups ---

    // ALB: accepts 80/443 from internet
    this.sgAlb = new ec2.SecurityGroup(this, 'SgAlb', {
      vpc,
      description: 'ALB - HTTP/HTTPS from internet',
      allowAllOutbound: true,
    });
    this.sgAlb.addIngressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(80), 'HTTP');
    this.sgAlb.addIngressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(443), 'HTTPS');

    // Backend: port 80 from ALB only
    this.sgBackend = new ec2.SecurityGroup(this, 'SgBackend', {
      vpc,
      description: 'Backend ECS tasks - port 80 from ALB',
      allowAllOutbound: true,
    });
    this.sgBackend.addIngressRule(this.sgAlb, ec2.Port.tcp(80), 'From ALB');

    // Frontend: port 3000 from ALB only
    this.sgFrontend = new ec2.SecurityGroup(this, 'SgFrontend', {
      vpc,
      description: 'Frontend ECS tasks - port 3000 from ALB',
      allowAllOutbound: true,
    });
    this.sgFrontend.addIngressRule(this.sgAlb, ec2.Port.tcp(3000), 'From ALB');

    // RDS: port 3306 from backend only
    this.sgRds = new ec2.SecurityGroup(this, 'SgRds', {
      vpc,
      description: 'RDS MariaDB - port 3306 from backend',
      allowAllOutbound: false,
    });
    this.sgRds.addIngressRule(this.sgBackend, ec2.Port.tcp(3306), 'From backend');

    // Redis: port 6379 from backend only
    this.sgRedis = new ec2.SecurityGroup(this, 'SgRedis', {
      vpc,
      description: 'Redis - port 6379 from backend',
      allowAllOutbound: false,
    });
    this.sgRedis.addIngressRule(this.sgBackend, ec2.Port.tcp(6379), 'From backend');

    // GPU Workers: no ingress (workers poll backend via HTTPS outbound)
    this.sgGpuWorkers = new ec2.SecurityGroup(this, 'SgGpuWorkers', {
      vpc,
      description: 'GPU workers - no ingress, HTTP/HTTPS egress only',
      allowAllOutbound: false,
    });
    this.sgGpuWorkers.addEgressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(80), 'HTTP to backend (when no TLS)');
    this.sgGpuWorkers.addEgressRule(ec2.Peer.anyIpv4(), ec2.Port.tcp(443), 'HTTPS to backend/S3');

    // --- Outputs ---
    new cdk.CfnOutput(this, 'VpcId', { value: vpc.vpcId });
    new cdk.CfnOutput(this, 'PrivateSubnets', {
      value: vpc.privateSubnets.map(s => s.subnetId).join(','),
    });
    if (this.natGatewayIds.length > 0) {
      new cdk.CfnOutput(this, 'NatGatewayIds', {
        value: this.natGatewayIds.join(','),
      });
    }

    // cdk-nag suppressions
    NagSuppressions.addResourceSuppressions(this.sgAlb, [
      { id: 'AwsSolutions-EC23', reason: 'ALB needs 0.0.0.0/0 for public web traffic' },
    ]);
    NagSuppressions.addResourceSuppressions(vpc, [
      { id: 'AwsSolutions-VPC7', reason: 'VPC flow logs added in MonitoringStack to log bucket' },
    ]);
  }
}
