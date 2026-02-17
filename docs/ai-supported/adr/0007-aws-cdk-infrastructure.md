# ADR-0007: AWS CDK Infrastructure

**Status:** Accepted
**Date:** 2026-02-16

## Context

The AI Product Manager is a multi-tenant SaaS platform for AI-powered image-to-video and video-to-video processing. The application stack:
- **Backend:** Laravel 11 (PHP 8.3) with Stancl Tenancy
- **Frontend:** Next.js 16 (standalone output)
- **Workers:** Python ComfyUI GPU workers (ADR-0005)
- **Databases:** MariaDB 10.11 (central + 2 tenant pools), Redis

The application is fully functional in local Docker (Laradock). This ADR defines the production AWS deployment using CDK (TypeScript) for automated infrastructure provisioning.

## Decision

### Architecture Overview

```
Internet -> CloudFront (CDN) -> ALB -> ECS Fargate (Backend + Frontend)
                                         |
                              Private Subnets
                              /      |        \
                          RDS MariaDB  Redis   GPU ASGs (Spot)
                              \      |        /
                               S3 Media Bucket
```

### CDK Stack Dependency Chain

```
NetworkStack -> DataStack -> ComputeStack -> GpuWorkerStack -> MonitoringStack
                                                                    |
                                                              CiCdStack (standalone)
```

### Key Components

| Component | Service | Configuration |
|-----------|---------|---------------|
| VPC | 10.0.0.0/16, 2 AZs | Public, Private, Isolated subnets |
| NAT | 1 NAT Gateway | Single AZ (saves ~$32/mo) |
| Backend | ECS Fargate ARM64 | 0.5 vCPU / 1 GB, nginx + php-fpm + scheduler + queue-worker |
| Frontend | ECS Fargate ARM64 | 0.25 vCPU / 512 MB, Next.js standalone |
| Database | RDS MariaDB 10.11 | db.t4g.small, 3 databases on one instance |
| Cache | ElastiCache Redis 7.1 | cache.t4g.micro, single node |
| Storage | S3 + CloudFront | Media bucket with OAC, PriceClass_100 |
| GPU Workers | EC2 ASGs per-workflow | 100% Spot, scale-to-zero (ADR-0005) |
| Monitoring | CloudWatch | Dashboard, alarms (P1/P2/P3), SNS alerts |

### Network Design

- **Public subnets (2):** ALB, NAT Gateway
- **Private subnets (2):** ECS Fargate, GPU ASGs
- **Isolated subnets (2):** RDS, ElastiCache (no internet access)
- **S3 Gateway Endpoint:** Free, saves NAT transfer costs
- **Security groups:** Least-privilege (ALB->Backend->RDS chain)

### Database Strategy

Single RDS MariaDB 10.11 instance hosts all 3 databases:
- `bp` (central): Users, Purchases, Payments, Effects, Tenants, Workers
- `tenant_pool_1`: TokenWallets, TokenTransactions, AiJobs (shard 1)
- `tenant_pool_2`: TokenWallets, TokenTransactions, AiJobs (shard 2)

MariaDB was chosen to match the local development environment (Laradock uses MariaDB, Laravel has a dedicated `mariadb` driver). A custom resource Lambda creates the tenant pool databases after RDS provisioning.

Environment variables map directly from `backend/config/database.php`:
- `CENTRAL_DB_HOST`, `CENTRAL_DB_USERNAME`, `CENTRAL_DB_PASSWORD`, `CENTRAL_DB_DATABASE`
- `TENANT_POOL_1_DB_HOST`, etc.
- `TENANT_POOL_2_DB_HOST`, etc.

### ECS Backend Architecture

The backend runs as a single Fargate task with 4 containers:
1. **nginx** (port 80): Reverse proxy to PHP-FPM, serves static assets
2. **php-fpm** (port 9000): Laravel application
3. **scheduler** sidecar: `php artisan schedule:work` (publishes CloudWatch metrics, cleans stale workers)
4. **queue-worker** sidecar: `php artisan queue:work` (processes Laravel queued jobs)

Container images are ARM64 for Graviton Fargate (20% cost savings). Backend scales 1-4 tasks based on CPU utilization (target 70%).

### GPU Worker Architecture

Implements ADR-0005 with CDK constructs:
- **WorkflowAsg construct:** Creates per-workflow ASG with Launch Template, Spot policies, step scaling (0->1), backlog tracking (1->N)
- **ScaleToZeroLambda construct:** Shared Lambda triggered by SNS when QueueDepth == 0 for 15 minutes
- AMI IDs stored in SSM Parameter Store, updated by Packer CI pipeline
- Workers self-register via fleet secret, poll backend for jobs, handle Spot interruptions

### Secrets Management

| Secret | Store | Path |
|--------|-------|------|
| RDS master credentials | Secrets Manager | `/bp/<stage>/rds/master-credentials` |
| Redis AUTH token | Secrets Manager | `/bp/<stage>/redis/auth-token` |
| Laravel APP_KEY | Secrets Manager | `/bp/<stage>/laravel/app-key` |
| Fleet secret | SSM Parameter Store | `/bp/<stage>/fleet-secret` |
| OAuth secrets | Secrets Manager | `/bp/<stage>/oauth/secrets` |

ECS containers use `ecs.Secret.fromSecretsManager()` for injection. GPU workers use `aws ssm get-parameter --with-decryption` in user-data.

### CI/CD Pipeline

**deploy.yml** (GitHub Actions, triggered on push to main):
1. `test-backend`: PHP 8.3 + MariaDB, `composer install`, `php artisan test`
2. `test-frontend`: Node 18, `pnpm install`, `pnpm build`
3. `build-and-push`: Build ARM64 Docker images, push to ECR
4. `deploy-infrastructure`: CDK deploy (manual gate, workflow_dispatch only)
5. `deploy-services`: `aws ecs update-service --force-new-deployment`
6. `run-migrations`: One-off ECS task for `php artisan migrate --force` (manual gate)

**build-ami.yml** (manual dispatch): Packer build with workflow_slug parameter, stores AMI ID in SSM.

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Compute | ECS Fargate ARM64 | Serverless, 20% cheaper with Graviton, no EC2 management |
| Database | Single RDS MariaDB 10.11 | Matches local dev, all 3 DBs on one instance, ~$47/mo |
| Cache | ElastiCache cache.t4g.micro | Offloads sessions/cache from RDS, ~$12/mo |
| GPU workers | EC2 ASG per-workflow, 100% Spot | ADR-0005 pattern, scale-to-zero, 60-70% savings |
| CDN | CloudFront PriceClass_100 | NA+EU only (cheapest), serves processed videos |
| Queue | Database driver (keep current) | Works fine for <50 workers, Redis is 1-line config change |
| NAT | Single Gateway (1 AZ) | Saves $32/mo, acceptable startup risk |
| VPC Endpoints | S3 Gateway only | Interface endpoints cost $7-15/mo each, add when NAT transfer > $50/mo |
| IaC | CDK TypeScript | Type-safe, composable constructs, better than raw CloudFormation |
| AMI builds | Packer | Standard tool for GPU AMIs with pre-loaded models |

## Cost Estimates (us-east-1)

| Component | Monthly Cost |
|-----------|-------------|
| RDS MariaDB db.t4g.small | ~$47 |
| ElastiCache cache.t4g.micro | ~$12 |
| NAT Gateway (1) | ~$32 |
| ECS Fargate (backend + frontend) | ~$25 |
| ALB | ~$16 |
| S3 + CloudFront (low usage) | ~$5 |
| CloudWatch (logs, metrics) | ~$5 |
| **Non-GPU total** | **~$142** |
| GPU Spot (idle) | $0 |
| GPU Spot (low: 20 jobs/day) | ~$10 |
| GPU Spot (high: 500 jobs/day) | ~$460 |

## Consequences

### Positive
- **Cost-optimized:** ~$131/mo idle (scale-to-zero GPU), ~$149/mo low usage
- **Production-ready:** Multi-AZ RDS (prod), auto-scaling, monitoring, alerting
- **Simple:** No Kubernetes, no over-engineering, standard AWS services
- **Automated:** CDK + GitHub Actions for infrastructure and deployments
- **Secure:** Private subnets, least-privilege SGs, Secrets Manager, encrypted storage

### Negative
- **Single NAT Gateway:** Single point of failure in staging (acceptable for startup)
- **Single Redis node:** No replication, AUTH requires replication group upgrade
- **ARM64 builds:** Cross-compilation adds ~2 min to CI builds
- **Packer AMIs:** Manual workflow trigger, AMI updates require ASG instance rotation

### Risks
- **Spot interruptions:** Mitigated by requeue (ADR-0005), capacity-rebalance, multi-AZ
- **Cold start:** ~3-5 min from zero GPU (acceptable for async video jobs)
- **Database scaling:** Single RDS instance, upgrade to db.t4g.medium/large when needed

## Files

### Infrastructure (CDK)
- `infrastructure/bin/app.ts` - CDK app entry point
- `infrastructure/lib/stacks/network-stack.ts` - VPC, subnets, security groups
- `infrastructure/lib/stacks/data-stack.ts` - RDS, Redis, S3, CloudFront, secrets
- `infrastructure/lib/stacks/compute-stack.ts` - ECS cluster, ALB, backend/frontend services
- `infrastructure/lib/stacks/gpu-worker-stack.ts` - Per-workflow GPU ASGs
- `infrastructure/lib/stacks/monitoring-stack.ts` - CloudWatch dashboard, alarms, SNS
- `infrastructure/lib/stacks/cicd-stack.ts` - ECR repositories
- `infrastructure/lib/constructs/workflow-asg.ts` - Reusable per-workflow ASG construct
- `infrastructure/lib/constructs/scale-to-zero-lambda.ts` - Shared scale-to-zero Lambda
- `infrastructure/lib/constructs/rds-init.ts` - Custom resource for tenant pool DB creation
- `infrastructure/lib/config/environment.ts` - Stage-specific configuration
- `infrastructure/lib/config/workflows.ts` - GPU workflow definitions

### Docker
- `infrastructure/docker/backend/Dockerfile.nginx` - Nginx reverse proxy (ARM64)
- `infrastructure/docker/backend/Dockerfile.php-fpm` - PHP-FPM Laravel app (ARM64)
- `infrastructure/docker/backend/nginx/default.conf` - Nginx configuration
- `frontend/Dockerfile` - Next.js standalone (existing, used as-is)

### Packer
- `infrastructure/packer/comfyui-worker.pkr.hcl` - AMI template
- `infrastructure/packer/variables.pkr.hcl` - Variable definitions
- `infrastructure/packer/scripts/install-nvidia-drivers.sh` - NVIDIA driver setup
- `infrastructure/packer/scripts/install-comfyui.sh` - ComfyUI installation
- `infrastructure/packer/scripts/install-python-worker.sh` - Worker script setup

### CI/CD
- `.github/workflows/deploy.yml` - Main deploy pipeline
- `.github/workflows/build-ami.yml` - GPU AMI build pipeline

## Related ADRs
- [ADR-0001: Tenancy DB Pools](0001-tenancy-db-pools.md)
- [ADR-0005: AWS Auto Scaling for ComfyUI Workers](0005-aws-autoscaling-comfyui-workers.md)
- [ADR-0006: AI Job Processing Pipeline](0006-ai-job-processing-pipeline.md)
