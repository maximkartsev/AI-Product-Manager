import * as cdk from 'aws-cdk-lib';
import * as ecr from 'aws-cdk-lib/aws-ecr';
import { Construct } from 'constructs';

export interface CiCdStackProps extends cdk.StackProps {
}

export class CiCdStack extends cdk.Stack {
  public readonly backendRepo: ecr.IRepository;
  public readonly frontendRepo: ecr.IRepository;

  constructor(scope: Construct, id: string, props: CiCdStackProps) {
    super(scope, id, props);

    // ========================================
    // ECR Repositories
    // ========================================

    this.backendRepo = new ecr.Repository(this, 'BackendRepo', {
      repositoryName: 'bp-backend',
      lifecycleRules: [
        {
          description: 'Keep last 10 images',
          maxImageCount: 10,
          rulePriority: 1,
        },
      ],
      removalPolicy: cdk.RemovalPolicy.DESTROY,
      emptyOnDelete: true,
    });

    this.frontendRepo = new ecr.Repository(this, 'FrontendRepo', {
      repositoryName: 'bp-frontend',
      lifecycleRules: [
        {
          description: 'Keep last 10 images',
          maxImageCount: 10,
          rulePriority: 1,
        },
      ],
      removalPolicy: cdk.RemovalPolicy.DESTROY,
      emptyOnDelete: true,
    });

    // ========================================
    // Outputs
    // ========================================

    new cdk.CfnOutput(this, 'BackendRepoUri', {
      value: this.backendRepo.repositoryUri,
      description: 'ECR repository URI for backend images',
    });

    new cdk.CfnOutput(this, 'FrontendRepoUri', {
      value: this.frontendRepo.repositoryUri,
      description: 'ECR repository URI for frontend images',
    });
  }
}
