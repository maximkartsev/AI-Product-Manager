# AWS Infrastructure

AWS CDK (TypeScript) infrastructure for the AI Product Manager platform — a multi-tenant Laravel + Next.js SaaS with GPU-accelerated AI processing.

## Architecture Overview

See the Day‑1 Operator Guide for a beginner-friendly runbook:
`infrastructure/DAY1_OPERATOR.md`

```
Internet
   │
   ├─── CloudFront (CDN) ──── S3 Media Bucket
   │
   ▼
  ALB  ─────────────────────────────────────  Public Subnets
   │
   ├── /api/* /sanctum/* /up ──► Backend ECS (Fargate ARM64)
   │                              ├─ nginx        (port 80)
   │                              ├─ php-fpm      (port 9000)
   │                              ├─ scheduler    (schedule:work)
   │                              └─ queue-worker (queue:work)
   │
   └── /* ─────────────────────► Frontend ECS (Fargate ARM64)
                                   └─ nextjs (port 3000)
                                                             Private Subnets
           GPU ASG (Spot, per-workflow) ──► ComfyUI workers
                                                             Isolated Subnets
           RDS MariaDB 10.11 ──► bp, tenant_pool_1, tenant_pool_2
           ElastiCache Redis 7.1
```

### Stacks

| Stack | ID | Purpose |
|-------|----|---------|
| **NetworkStack** | `bp-<stage>-network` | VPC (10.0.0.0/16, 2 AZs), subnets (public/private/isolated), NAT Gateway, 6 security groups, S3 gateway endpoint |
| **DataStack** | `bp-<stage>-data` | RDS MariaDB 10.11, ElastiCache Redis 7.1, S3 media bucket, S3 logs bucket, CloudFront CDN, secrets (APP_KEY, fleet, OAuth) |
| **ComputeStack** | `bp-<stage>-compute` | ECS Fargate cluster, ALB (HTTP/HTTPS), backend service (4 containers), frontend service, auto-scaling |
| **GpuWorkerStack** | `bp-<stage>-gpu` | Per-workflow EC2 ASGs (100% Spot), step scaling 0→1, backlog tracking 1→N, scale-to-zero Lambda |
| **MonitoringStack** | `bp-<stage>-monitoring` | CloudWatch dashboard, P1/P2/P3 alarms, SNS alert topic, GPU worker log groups |
| **CiCdStack** | `bp-<stage>-cicd` | ECR repositories (backend + frontend) with lifecycle policies (keep last 10 images) |

Dependency chain: `Network → Data → Compute → GPU → Monitoring`. CiCd is standalone.

### Cost Estimates (us-east-1, staging)

| Component | Monthly |
|-----------|---------|
| RDS MariaDB db.t4g.small | ~$47 |
| NAT Gateway (1) | ~$32 |
| ECS Fargate (backend + frontend) | ~$25 |
| ALB | ~$16 |
| ElastiCache cache.t4g.micro | ~$12 |
| S3 + CloudFront + CloudWatch | ~$10 |
| **Non-GPU total** | **~$142** |
| GPU Spot (idle / scale-to-zero) | $0 |
| GPU Spot (low: ~20 jobs/day) | ~$10 |
| GPU Spot (high: ~500 jobs/day) | ~$460 |

## Prerequisites

- **AWS CLI v2** — configured with credentials (`aws sts get-caller-identity`)
- **Node.js 18+** — for CDK
- **AWS CDK CLI** — installed globally (`npm i -g aws-cdk`) or use `npx cdk`
- **Docker** — for building ARM64 container images
- **Packer** — for building GPU AMIs (only needed for worker AMIs)

### IAM Permissions

The deploying principal needs broad permissions (or `AdministratorAccess` for initial setup):
- CloudFormation, EC2, ECS, ECR, ELBv2, RDS, ElastiCache, S3, CloudFront
- Secrets Manager, SSM, IAM (role creation), CloudWatch, SNS, Lambda, Auto Scaling

### ACM Certificate (optional)

For HTTPS with a custom domain, create an ACM certificate in the deployment region **before** deploying:

```bash
aws acm request-certificate \
  --domain-name app.example.com \
  --validation-method DNS \
  --region us-east-1
```

Notes:
- `--domain-name` should be the **exact hostname you want users to visit** (and should match CDK context `domainName`), e.g. `app.yourdomain.com`.
- You must **own/control DNS** for the domain so you can add the ACM DNS validation record.
- Do **not** use the ALB DNS name (`*.elb.amazonaws.com`) — ACM will not issue certificates for that.
- For an **ALB certificate**, request it in the **same region** as the ALB (your `CDK_DEFAULT_REGION`). (`us-east-1` is shown here because this repo defaults to `us-east-1`.)
- After ACM is issued, create a DNS record (Route 53 or your DNS provider) pointing `app.example.com` to the ALB DNS name (this repo does not automatically create Route 53 records).

Without a certificate, the ALB runs HTTP-only on port 80.

## Quick Start

```bash
cd infrastructure

# Install dependencies
npm ci

# Set AWS account/region
export CDK_DEFAULT_ACCOUNT=123456789012
export CDK_DEFAULT_REGION=us-east-1

# Optional: set alert email and cost budget (env or CDK context)
export ALERT_EMAIL=ops@example.com
# Or pass context: --context alertEmail=ops@example.com --context budgetMonthlyUsd=200

# Optional: set custom domain + ACM cert via context
# --context domainName=app.example.com --context certificateArn=arn:aws:acm:...
# Optional: add owner tag via context
# --context owner=team-platform

# Bootstrap CDK (first time per account/region)
npx cdk bootstrap

# Preview changes
npx cdk diff --all

# Deploy everything
npx cdk deploy --all
```

### Post-Deploy: Set Secrets

After the first deploy, populate manually-managed secrets:

```bash
STAGE=staging

# 1. Laravel APP_KEY
php artisan key:generate --show  # run locally to generate
aws secretsmanager put-secret-value \
  --secret-id "/bp/${STAGE}/laravel/app-key" \
  --secret-string "base64:YOUR_GENERATED_KEY"

# 2. Fleet secret (GPU worker registration)
aws ssm put-parameter \
  --name "/bp/${STAGE}/fleet-secret" \
  --value "$(openssl rand -hex 32)" \
  --type String \
  --overwrite
# Used by backend (COMFYUI_FLEET_SECRET) and GPU workers

# 3. OAuth secrets (if using social login)
aws secretsmanager put-secret-value \
  --secret-id "/bp/${STAGE}/oauth/secrets" \
  --secret-string '{"google_client_secret":"...","apple_client_secret":"...","tiktok_client_secret":"..."}'
```

## Configuration

### Environment Presets (`lib/config/environment.ts`)

| Parameter | Staging | Production |
|-----------|---------|------------|
| `rdsInstanceClass` | db.t4g.small | db.t4g.medium |
| `rdsMultiAz` | false | true |
| `redisNodeType` | cache.t4g.micro | cache.t4g.small |
| `natGateways` | 1 | 2 |
| `backendCpu` / `Memory` | 512 / 1024 | 1024 / 2048 |
| `frontendCpu` / `Memory` | 256 / 512 | 512 / 1024 |

Stage is selected via CDK context: `npx cdk deploy --context stage=production`.

#### Stages: staging vs production

- **staging**: default stage in this repo (resource names like `bp-staging`, smaller/cheaper defaults).
- **production**: appears when you deploy with `--context stage=production` (resource names like `bp-production`, larger/HA defaults). A separate AWS account is recommended.

Important: GitHub Actions **Environments** (e.g. `staging-migrations`) are GitHub approval/secret scopes, not the AWS “stage”.

CI note: `.github/workflows/deploy.yml` is currently configured for **staging** (ECS cluster/services and ECR repos use `-staging`). To deploy production from CI you’d typically create a separate workflow (or add an input) and use a separate AWS role + GitHub environment approvals.

### CDK Context (`cdk.json`)

| Key | Description | Required |
|-----|-------------|----------|
| `domainName` | Custom domain (e.g. `app.example.com`) | No (ALB DNS used if empty) |
| `certificateArn` | ACM certificate ARN for HTTPS | No (HTTP-only if empty) |
| `alertEmail` | Email for ops alert SNS subscription | No |
| `budgetMonthlyUsd` | Monthly budget (USD) for AWS Budgets alerts | No |
| `owner` | Cost allocation tag value for Owner | No |

### AWS IAM & Credentials (Local vs GitHub Actions)

You will usually use **two identities**:

- **Local (you / CDK)**: AWS CLI credentials on your machine for `cdk diff/deploy`.
- **CI (GitHub Actions)**: an IAM **role** assumed via OIDC (`AWS_DEPLOY_ROLE_ARN`). No IAM user keys stored in GitHub.

#### Local AWS CLI for CDK

Preferred: AWS IAM Identity Center (SSO) with an admin/infra permission set.

Quick start (IAM user):
1. AWS Console → **IAM → Users → Create user**
2. On **Set permissions**, choose **Add user to group**, create a group (e.g. `bp-admin`) and attach **AdministratorAccess**.
3. Create an access key for the user and run `aws configure` locally.

CDK needs broad permissions across CloudFormation, IAM, EC2/VPC, ECS, ECR, ELBv2, RDS, ElastiCache, S3, CloudFront, Secrets Manager, SSM, CloudWatch, SNS, Auto Scaling, and Lambda.

#### GitHub Actions OIDC role

See [GitHub Actions to AWS (OIDC)](#github-actions-to-aws-oidc) for the exact trust policy, permissions, and GitHub secrets/environments.

### Workflow Configuration (`lib/config/workflows.ts`)

Each workflow gets a dedicated GPU ASG. To add a new workflow:

```typescript
// lib/config/workflows.ts
export const WORKFLOWS: WorkflowConfig[] = [
  {
    slug: 'image-to-video',           // Must match backend workflows.slug column
    displayName: 'Image to Video',     // CloudWatch dashboard label
    amiSsmParameter: '/bp/ami/image-to-video',  // SSM path (updated by Packer CI)
    instanceTypes: ['g4dn.xlarge', 'g5.xlarge'], // Spot priority order
    maxSize: 10,                       // ASG max instances
    warmupSeconds: 300,                // Instance warmup for scaling
    backlogTarget: 2,                  // Target jobs per instance
    scaleToZeroMinutes: 15,            // Minutes at 0 queue before scale-in
  },
  // Add new workflows here, then run: npx cdk deploy bp-<stage>-gpu
];
```

## Deployment

### First-Time Deploy

```bash
cd infrastructure && npm ci

export CDK_DEFAULT_ACCOUNT=123456789012
export CDK_DEFAULT_REGION=us-east-1

# 1. Bootstrap CDK
npx cdk bootstrap

# 2. Deploy CI/CD stack first (creates ECR repos)
npx cdk deploy bp-staging-cicd

# 3. Build and push initial Docker images (from repo root)
#    See "Docker Images" section below

# 4. Deploy remaining stacks
npx cdk deploy bp-staging-network bp-staging-data bp-staging-compute bp-staging-gpu bp-staging-monitoring

# 5. Set secrets (see Post-Deploy above)

# 6. Ensure ECR images exist (bp-backend-<stage>:nginx-latest/php-latest, bp-frontend-<stage>:latest)
#    CI build-and-push populates these tags; manual build instructions below.

# 7. Run initial migrations
aws ecs run-task \
  --cluster bp-staging \
  --task-definition bp-staging-backend \
  --overrides '{"containerOverrides":[{"name":"php-fpm","command":["php","artisan","migrate","--force"]}]}' \
  --launch-type FARGATE \
  --network-configuration '{"awsvpcConfiguration":{"subnets":["subnet-xxx"],"securityGroups":["sg-xxx"]}}'
```

### Updating Application Code (CI/CD)

Manually run `.github/workflows/deploy.yml`:

```
Actions → "Deploy" → Run workflow
```

1. **test-backend** — PHP 8.3 + MariaDB, runs `php artisan test`
2. **test-frontend** — Node 18 + pnpm, runs `pnpm build`
3. **build-and-push** — Builds ARM64 Docker images, pushes to ECR (tags: `<sha7>` + `latest`)
4. **deploy-services** — `aws ecs update-service --force-new-deployment` for both services
5. **deploy-infrastructure** — CDK deploy (requires GitHub environment approval: `staging-infra`). Optional if you deploy infrastructure locally.
6. **run-migrations** — One-off ECS tasks for DB migrations (requires GitHub environment approval: `staging-migrations`)

### Infrastructure Changes

```bash
cd infrastructure

# Preview what will change
npx cdk diff --all

# Deploy specific stacks
npx cdk deploy bp-staging-data bp-staging-compute

# Deploy everything
npx cdk deploy --all
```

### Database Migrations

Migrations run as one-off ECS tasks (not during normal deploys):

- **ECS service** keeps your app running (backend/frontend tasks stay up behind the ALB).
- **ECS task** is a single, one-off run of a task definition. Here we use `aws ecs run-task` to start a temporary Fargate task and override the `php-fpm` command to run migrations. The task stops when the command finishes.
- These migration tasks run only when you **manually** run the commands below, or when you run the **Deploy** workflow and approve the `run-migrations` job. They do **not** run during `deploy-services`.

Safety:
- `php artisan migrate` does **not** wipe the database by default, but migrations can still be destructive (e.g. dropping/altering columns). Treat production migrations as potentially risky and ensure you have backups/rollback plans.

Notes:
- `tenancy:pools-migrate` **creates missing tenant pool databases** before applying migrations (uses the central connection).
- Flags: `--central`, `--tenant`, `--fresh`, `--pools=tenant_pool_1,tenant_pool_2`.
- Default behavior runs **both** central and tenant pool migrations.

```bash
# Central database
aws ecs run-task \
  --cluster bp-staging \
  --task-definition bp-staging-backend \
  --overrides '{"containerOverrides":[{"name":"php-fpm","command":["php","artisan","migrate","--force"]}]}' \
  --launch-type FARGATE \
  --network-configuration '{"awsvpcConfiguration":{"subnets":["subnet-xxx"],"securityGroups":["sg-xxx"]}}'

# Tenant pool databases
aws ecs run-task \
  --cluster bp-staging \
  --task-definition bp-staging-backend \
  --overrides '{"containerOverrides":[{"name":"php-fpm","command":["php","artisan","tenancy:pools-migrate","--force"]}]}' \
  --launch-type FARGATE \
  --network-configuration '{"awsvpcConfiguration":{"subnets":["subnet-xxx"],"securityGroups":["sg-xxx"]}}'
```

## GPU Workers

### ComfyUI Asset Bundles (models / LoRAs / VAEs)

We manage ComfyUI assets as **versioned bundles** in S3. Each bundle is a self-contained prefix that can be synced to `/opt/comfyui` on GPU nodes (dev or autoscaled).

**S3 layout**

- Models bucket: `bp-models-<account>-<stage>`
- Upload staging: `uploads/<workflow_slug>/<uuid>/<uuid>.<ext>`
- Bundles: `bundles/<workflow_slug>/<bundle_id>/`
  - `models/` → ComfyUI `models/*` (checkpoints, loras, vae, etc.)
  - `custom_nodes/` → ComfyUI `custom_nodes/*`
  - `manifest.json` → bundle manifest

**Kind → target path mapping**

- `checkpoint` → `models/checkpoints/`
- `lora` → `models/loras/`
- `vae` → `models/vae/`
- `embedding` → `models/embeddings/`
- `controlnet` → `models/controlnet/`
- `custom_node` → `custom_nodes/`
- `other` → `models/other/`

**Manifest schema (stored at `.../manifest.json`)**

```json
{
  "bundle_id": "uuid",
  "workflow_slug": "image-to-video",
  "created_at": "2026-02-18T12:34:56Z",
  "notes": "Optional notes",
  "assets": [
    {
      "kind": "checkpoint | lora | vae | embedding | controlnet | custom_node | other",
      "original_filename": "my-model.safetensors",
      "size_bytes": 123456789,
      "sha256": "optional sha256",
      "s3_key": "bundles/image-to-video/<bundle_id>/models/checkpoints/my-model.safetensors",
      "target_path": "models/checkpoints/my-model.safetensors"
    }
  ]
}
```

**Active bundle pointer**

For each workflow + stage, the active bundle is stored in SSM:

```
/bp/<stage>/assets/<workflow_slug>/active_bundle
```

GPU nodes sync the active bundle on boot. To promote a new bundle you:
1) build AMI (Packer), 2) update the SSM bundle pointer, 3) roll the ASG.

**Smart-skip boot sync (hybrid)**

- If the AMI was baked with a bundle, Packer writes `/opt/comfyui/.baked_bundle_id`.
- On boot, if `ACTIVE_BUNDLE` equals `BAKED_BUNDLE_ID`, the instance **skips** S3 sync.
- If they differ, it runs `aws s3 sync` to pull the delta.

**Important: AWS S3 vs local MinIO**

- **Bundles live in AWS S3 only** (`bp-models-<account>-<stage>`). Local MinIO is **dev-only** and not reachable by AWS EC2/SSM/Actions unless you explicitly expose and configure it.
- **Bucket discovery**:
  - CDK outputs (DataStack), or
  - `aws ssm get-parameter --name /bp/<stage>/models/bucket --query Parameter.Value --output text`
- **Storage config split**:
  - Media uploads use the default `s3` disk (`AWS_*`) → MinIO locally, S3 in staging/prod.
  - ComfyUI models/logs use dedicated disks (`COMFYUI_MODELS_*`, `COMFYUI_LOGS_*`) so models stay in AWS S3 even when media is local.
- **AWS S3 readers**: `build-ami.yml` (Packer), `apply-comfyui-bundle.yml` (SSM), GPU worker boot sync (user-data).

#### One-time setup (per stage)

- **Deploy infrastructure (creates bucket/roles/SSM pointers)**:

```bash
# Example (staging)
cd infrastructure
npx cdk deploy bp-staging-data bp-staging-compute bp-staging-gpu
```

- **Deploy updated backend/frontend code** (so the new admin endpoints + `/admin/assets` UI exist):
  - See [Updating Application Code (CI/CD)](#updating-application-code-cicd) and run the `Deploy` workflow.

- **Run backend migrations** (creates central DB tables for assets/bundles/audit logs):
  - Via GitHub Actions: run `Deploy` and approve the `run-migrations` job, or
  - Via CLI (staging example): see [Database Migrations](#database-migrations).

- **Set required secrets** (if not already done):
  - Fleet secret: `/bp/<stage>/fleet-secret` (SSM)
  - Laravel `APP_KEY`: `/bp/<stage>/laravel/app-key` (Secrets Manager)

- **Optional: enable GitHub “apply bundle” audit logging**:
  - Read the asset-ops secret:

```bash
aws secretsmanager get-secret-value \
  --secret-id "/bp/<stage>/asset-ops/secret" \
  --query SecretString --output text
```

  - Store it as GitHub secret `ASSET_OPS_SECRET`
  - Set GitHub secret `ASSET_OPS_API_URL` to your backend API base, e.g. `https://app.example.com/api`

#### Runbook: create → activate → promote

1. **Upload asset files**: open **Admin → Assets** (`/admin/assets`) and upload a model/LoRA/VAE/custom node file.
2. **Create a bundle**: select the workflow, select asset files, add notes, click **Create Bundle**.
   - This copies files into `bundles/<workflow_slug>/<bundle_id>/...` and writes `manifest.json`.
3. **Activate bundle**:
   - Click **Activate Staging** or **Activate Prod**.
   - This writes the active pointer in SSM:

```bash
aws ssm get-parameter \
  --name "/bp/<stage>/assets/<workflow_slug>/active_bundle" \
  --query "Parameter.Value" --output text
```

4. **Bake an AMI from the bundle (recommended for stage/prod)**:
   - Run GitHub Actions → **Build GPU AMI** (`build-ami.yml`) with:
     - `workflow_slug`
     - `models_s3_bucket`: `bp-models-<account>-<stage>`
     - `models_s3_prefix`: `bundles/<workflow_slug>/<bundle_id>`
     - `bundle_id`: (recommended) stored in the AMI as `/opt/comfyui/.baked_bundle_id` for smart-skip behavior
     - `packer_instance_profile` (usually required): an EC2 instance profile with S3 read to the bundle prefix (Packer’s `aws s3 sync` runs **inside** the build instance)
       - Quick start: create an EC2 role + instance profile and attach `AmazonS3ReadOnlyAccess` (or a prefix-restricted S3 read policy).
   - The workflow writes the AMI alias to `/bp/ami/<workflow_slug>` (data type `aws:ec2:image`).
5. **Roll the ASG** so new instances pick up the new AMI + active bundle:
   - AWS Console → Auto Scaling Groups → start **Instance refresh**, or
   - CLI (example):

```bash
aws autoscaling start-instance-refresh \
  --auto-scaling-group-name "asg-<stage>-<workflow_slug>" \
  --preferences '{"MinHealthyPercentage":90,"InstanceWarmup":300}'
```

   - Scale-to-zero → scale up (for stage), or
   - Replace instances via your standard rollout procedure.

#### Troubleshooting

- **Boot sync logs**: `/var/log/comfyui-asset-sync.log` on the GPU instance (and `journalctl -u comfyui.service` / `-u comfyui-worker.service`).
- **No active bundle**: the default SSM value is `none`, which skips sync.
- **Wrong AMI in ASG**: verify `/bp/ami/<workflow_slug>` exists and is `aws:ec2:image`.

### Building AMIs (Packer)

AMIs are built via GitHub Actions (`.github/workflows/build-ami.yml`, manual dispatch):

```
Actions → "Build GPU AMI" → Run workflow
  workflow_slug: image-to-video
  instance_type: g4dn.xlarge  (default)
```

The pipeline:
1. Checks out code
2. Copies `worker/comfyui_worker.py` into Packer context
3. Runs `packer build` with NVIDIA drivers, ComfyUI, Python worker
4. Stores the new AMI ID in SSM Parameter Store (`/bp/ami/<workflow-slug>`)

To build locally:

```bash
cd infrastructure/packer

packer init .
packer build \
  -var "workflow_slug=image-to-video" \
  -var "instance_type=g4dn.xlarge" \
  -var "aws_region=us-east-1" \
  .
```

Packer provisioning steps:
1. `install-nvidia-drivers.sh` — NVIDIA GPU drivers + CUDA
2. `install-comfyui.sh` — ComfyUI server at `/opt/comfyui`
3. `install-python-worker.sh` — Worker script at `/opt/worker`
4. Model sync from S3 (optional, if `models_s3_bucket` var is set)

### Apply Bundle to Dev Instance (manual)

Use `.github/workflows/apply-comfyui-bundle.yml` to sync a bundle onto a **running dev GPU instance**:

**Inputs:**
- `instance_id` — EC2 instance ID
- `workflow_slug` — e.g. `image-to-video`
- `bundle_id` — bundle UUID
- `models_bucket` — `bp-models-<account>-<stage>`
- `logs_bucket` — optional, `bp-logs-<account>-<stage>`

**Requirements:**
- The dev instance must have **SSM** enabled and an instance profile with **S3 read** to the models bucket.
- The workflow will upload SSM command output to the logs bucket (if provided).
- If `ASSET_OPS_API_URL` + `ASSET_OPS_SECRET` are set, the workflow also records an audit log via the backend.

### Adding a New Workflow

1. Add entry to `lib/config/workflows.ts` (see Configuration section above)
2. Build a Packer AMI for the new workflow slug
3. Deploy: `npx cdk deploy bp-staging-gpu bp-staging-monitoring`
4. The new workflow gets its own ASG, CloudWatch alarms, and dashboard row

### Monitoring Workers

Custom CloudWatch metrics (namespace: `ComfyUI/Workers`, dimension: `WorkflowSlug`):
- `QueueDepth` — pending jobs for this workflow
- `BacklogPerInstance` — jobs per active worker
- `ActiveWorkers` — running instances
- `JobProcessingP50` — median job duration
- `ErrorRate` — % of failed jobs
- `SpotInterruptionCount` — Spot reclamations

### Scale-to-Zero Behavior

1. Queue empties → `QueueDepth == 0` for 15 min (configurable via `scaleToZeroMinutes`)
2. CloudWatch alarm triggers → SNS → Lambda
3. Lambda sets ASG `DesiredCapacity = 0`
4. New job arrives → `QueueDepth > 0` alarm → step scaling policy sets capacity to 1
5. More jobs → backlog tracking scales 1→N based on `BacklogPerInstance`

## Docker Images

Both backend images target **linux/arm64** (Graviton Fargate). Build from the **repository root**:

```bash
# Backend: Nginx reverse proxy
docker buildx build \
  --platform linux/arm64 \
  -f infrastructure/docker/backend/Dockerfile.nginx \
  -t bp-backend:nginx-latest .

# Backend: PHP-FPM (Laravel)
docker buildx build \
  --platform linux/arm64 \
  -f infrastructure/docker/backend/Dockerfile.php-fpm \
  -t bp-backend:php-latest .

# Frontend: Next.js (uses existing frontend/Dockerfile)
docker buildx build \
  --platform linux/arm64 \
  -f frontend/Dockerfile \
  -t bp-frontend:latest ./frontend
```

### Push to ECR (manual)

```bash
AWS_REGION=us-east-1
AWS_ACCOUNT_ID=123456789012
STAGE=staging

aws ecr get-login-password --region $AWS_REGION | \
  docker login --username AWS --password-stdin ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com

docker tag bp-backend:nginx-latest ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend-${STAGE}:nginx-latest
docker tag bp-backend:php-latest ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend-${STAGE}:php-latest
docker tag bp-frontend:latest ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-frontend-${STAGE}:latest

docker push ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend-${STAGE}:nginx-latest
docker push ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend-${STAGE}:php-latest
docker push ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-frontend-${STAGE}:latest
```

Backend Nginx config: `infrastructure/docker/backend/nginx/default.conf` — proxies PHP to `127.0.0.1:9000`, 300s fastcgi timeout, 1GB upload limit.

## Correct Uploads

### Media upload flow (end-user)

1. **Initialize upload** — `POST /api/videos/uploads` with:
   - `effect_id` (required, numeric)
   - `mime_type` (required)
   - `size` (required, bytes)
   - `original_filename` (required)
   - `file_hash` (optional)
2. **Upload file bytes** — use the returned `upload_url` and **include all `upload_headers`**. Upload must complete before the presigned URL expires.
3. **Create the video record** — `POST /api/videos` with `original_file_id` (from step 1), `effect_id`, and any optional fields (e.g., `title`). This starts processing.

Defaults and constraints (from backend config):
- **Allowed MIME types**: `video/mp4`, `video/quicktime`, `video/webm`, `video/x-matroska`
- **Max upload size**: `1073741824` bytes (1 GB) via `COMFYUI_UPLOAD_MAX_BYTES`
- **Presigned URL TTL**: `900` seconds via `COMFYUI_PRESIGNED_TTL_SECONDS`

### Deployment artifact uploads

- **ECR images (ECS runtime)**:
  - Backend repo: `bp-backend-<stage>`
    - `nginx-latest`
    - `php-latest`
  - Frontend repo: `bp-frontend-<stage>`
    - `latest`
    - `<sha7>` (CI tag)
  - CI `deploy.yml` builds and pushes these tags on every `main` push.

- **GPU AMI builds**:
  - Trigger GitHub Actions `build-ami.yml` (manual dispatch).
  - Input: `workflow_slug`, optional `instance_type`.
  - Output: AMI ID written to SSM at `/bp/ami/<workflow-slug>`.

- **Optional model sync** (Packer):
  - Set `models_s3_bucket` and `models_s3_prefix` (bundle root like `bundles/<workflow_slug>/<bundle_id>`) to sync during AMI build:
    - `.../models/` → `/opt/comfyui/models/`
    - `.../custom_nodes/` → `/opt/comfyui/custom_nodes/` (optional)

## Secrets Reference

| Secret | Store | Path | Auto-Generated? | Manual Setup |
|--------|-------|------|-----------------|--------------|
| RDS master credentials | Secrets Manager | `/bp/<stage>/rds/master-credentials` | Yes (CDK) | — |
| Redis AUTH token | Secrets Manager | `/bp/<stage>/redis/auth-token` | Yes (CDK) | — |
| Laravel APP_KEY | Secrets Manager | `/bp/<stage>/laravel/app-key` | No (placeholder) | `aws secretsmanager put-secret-value --secret-id /bp/<stage>/laravel/app-key --secret-string "base64:..."` |
| Fleet secret | SSM Parameter Store | `/bp/<stage>/fleet-secret` | No (`CHANGE_ME_AFTER_DEPLOY`) | `aws ssm put-parameter --name /bp/<stage>/fleet-secret --value "..." --type String --overwrite` |
| Asset ops secret | Secrets Manager | `/bp/<stage>/asset-ops/secret` | Yes (CDK) | Use the secret value as `ASSET_OPS_SECRET` in GitHub Actions |
| Models bucket | SSM Parameter Store | `/bp/<stage>/models/bucket` | Yes (CDK) | — |
| OAuth secrets | Secrets Manager | `/bp/<stage>/oauth/secrets` | No (placeholder) | `aws secretsmanager put-secret-value --secret-id /bp/<stage>/oauth/secrets --secret-string '{"google_client_secret":"..."}'` |
| GPU AMI IDs | SSM Parameter Store | `/bp/ami/<workflow-slug>` | Yes (Packer CI) | `aws ssm put-parameter --name /bp/ami/<slug> --value ami-xxx --data-type "aws:ec2:image" --type String --overwrite` |
| Active asset bundle | SSM Parameter Store | `/bp/<stage>/assets/<workflow_slug>/active_bundle` | Yes (CDK, default: `none`) | Set via **Admin → Assets**, or `aws ssm put-parameter --name /bp/<stage>/assets/<slug>/active_bundle --value "<bundle_id>" --type String --overwrite` |
| Redis endpoint | SSM Parameter Store | `/bp/<stage>/redis/endpoint` | Yes (CDK) | — |

## Monitoring & Alerts

### Dashboard

Name: `bp-<stage>` (e.g. `bp-staging`)

| Row | Widgets |
|-----|---------|
| 1 — App Health | ALB Requests & 5xx Errors, ALB Latency (p50/p95/p99) |
| 2 — Data Layer | RDS CPU & Connections, RDS Free Storage |
| 3 — NAT Gateway | NAT Bytes In/Out, Port Allocation Errors |
| 4+ — GPU Workers | Per-workflow: Queue Depth & Workers, Performance & Errors |

### Log Groups & Retention

- Backend: `/ecs/bp-backend-<stage>`
- Frontend: `/ecs/bp-frontend-<stage>`
- GPU workers: `/gpu-workers/<workflow-slug>`

Retention:
- **Production**: 30 days
- **Non-production**: 7 days

### Alarm Tiers

| Tier | Alarm | Threshold | Period |
|------|-------|-----------|--------|
| **P1** | ALB 5xx Critical | ≥ 50 errors | 5 min |
| **P1** | RDS CPU Critical | ≥ 95% | 10 min |
| **P1** | RDS Storage Low | < 2 GB free | 5 min |
| **P2** | ALB 5xx Warning | ≥ 10 errors | 5 min |
| **P2** | RDS CPU Warning | ≥ 80% | 5 min |
| **P2** | Per-workflow Error Rate | ≥ 20% | 5 min |
| **P3** | Per-workflow Queue Deep | ≥ 10 jobs | 30 min |

### SNS Topic

Topic name: `bp-<stage>-ops-alerts`. Subscribes `ALERT_EMAIL` if configured.

To add Slack/PagerDuty: create an SNS subscription to the topic ARN with the appropriate protocol (HTTPS webhook for Slack via AWS Chatbot, HTTPS endpoint for PagerDuty).

### Budget Alerts (optional)

To enable monthly cost alerts, set **both**:
- `alertEmail` (context or `ALERT_EMAIL`)
- `budgetMonthlyUsd` (context)

Example:

```bash
npx cdk deploy --all \
  --context alertEmail=ops@example.com \
  --context budgetMonthlyUsd=200
```

### What to check first (incident triage)

- ALB 5xx alarms + target health
- RDS CPU + FreeStorageSpace
- GPU QueueDepth vs ActiveWorkers
- NAT Port Allocation errors (if uploads/downloads are failing)

## Shutdown

### Graceful scale-down (keep data)

1. Scale ECS services to zero:

```bash
aws ecs update-service --cluster bp-staging --service bp-staging-backend --desired-count 0
aws ecs update-service --cluster bp-staging --service bp-staging-frontend --desired-count 0
```

2. Scale GPU ASGs to zero (repeat for each workflow slug):

```bash
aws autoscaling update-auto-scaling-group \
  --auto-scaling-group-name asg-staging-image-to-video \
  --min-size 0 --max-size 0 --desired-capacity 0
```

3. Verify no running tasks/instances:

```bash
aws ecs describe-services \
  --cluster bp-staging \
  --services bp-staging-backend bp-staging-frontend \
  --query 'services[].{name:serviceName,running:runningCount,desired:desiredCount}'

aws autoscaling describe-auto-scaling-groups \
  --auto-scaling-group-names asg-staging-image-to-video \
  --query 'AutoScalingGroups[].{name:AutoScalingGroupName,desired:DesiredCapacity,instances:Instances[].InstanceId}'
```

### Full teardown (destroy infrastructure)

```bash
cd infrastructure
npx cdk destroy bp-staging-monitoring bp-staging-gpu bp-staging-compute bp-staging-data bp-staging-network bp-staging-cicd
```

Notes:
- **Production**: RDS deletion protection is enabled; expect manual snapshot/teardown steps.
- **S3 media bucket** is retained in production; delete manually if required.
- **Staging**: buckets auto-delete, and RDS snapshots are created on delete.

## Troubleshooting

### Common Issues

| Symptom | Diagnosis | Fix |
|---------|-----------|-----|
| ECS tasks stuck in PENDING | No capacity, or image pull failures | Check ECR image exists, check SG allows outbound to ECR/S3 |
| Backend returns 502 | PHP-FPM container not ready or crashed | Check `/ecs/bp-backend-<stage>` logs, verify DB connectivity |
| GPU workers not starting | AMI not found or SSM parameter missing | Verify SSM parameter `/bp/ami/<slug>` has a valid AMI ID |
| Scale-to-zero not working | Lambda not triggered | Check alarm state, verify SNS subscription, check Lambda logs |
| ALB returns 404 | No healthy targets | Check target group health, verify ECS service desired count > 0 |
| CDK deploy fails on secrets | Secret already exists | Import with `cdk import` or delete manually from Secrets Manager |
| RDS connection refused | Security group misconfigured | Verify `sgRds` allows port 3306 from `sgBackend` |

### Useful Commands

```bash
# ECS Exec into running backend container
aws ecs execute-command \
  --cluster bp-staging \
  --task <task-id> \
  --container php-fpm \
  --interactive \
  --command "/bin/bash"

# Tail backend logs
aws logs tail /ecs/bp-backend-staging --follow

# Tail GPU worker logs
aws logs tail /gpu-workers/image-to-video --follow

# Check ECS service status
aws ecs describe-services \
  --cluster bp-staging \
  --services bp-staging-backend bp-staging-frontend \
  --query 'services[].{name:serviceName,status:status,running:runningCount,desired:desiredCount}'

# Check GPU ASG status
aws autoscaling describe-auto-scaling-groups \
  --auto-scaling-group-names asg-staging-image-to-video \
  --query 'AutoScalingGroups[].{name:AutoScalingGroupName,desired:DesiredCapacity,min:MinSize,max:MaxSize,instances:Instances[].InstanceId}'

# Force new deployment (pick up latest image)
aws ecs update-service --cluster bp-staging --service bp-staging-backend --force-new-deployment

# Check CloudWatch alarm states
aws cloudwatch describe-alarms \
  --alarm-name-prefix staging-p \
  --query 'MetricAlarms[].{name:AlarmName,state:StateValue}'
```

## CI/CD Reference

### `deploy.yml`

**Trigger:** Manual dispatch only (`workflow_dispatch`).

```
test-backend ──┐
               ├──► build-and-push ──┬──► deploy-services ──► run-migrations*
test-frontend ─┘                     └──► deploy-infrastructure*

* = requires GitHub environment approval (see “Required GitHub Environments”)
```

### `build-ami.yml`

**Trigger:** Manual dispatch only (`workflow_dispatch`).

**Inputs:**
- `workflow_slug` (required) — e.g. `image-to-video`
- `instance_type` (optional, default: `g4dn.xlarge`) — build instance
- `models_s3_bucket` (optional) — models bucket (e.g. `bp-models-<account>-<stage>`)
- `models_s3_prefix` (optional) — bundle prefix (e.g. `bundles/image-to-video/<bundle_id>`)
- `bundle_id` (optional) — used for AMI tagging/audit
- `packer_instance_profile` (recommended / usually required) — instance profile name/ARN with S3 read access (used by `aws s3 sync` inside the build instance)

**Output:** AMI ID stored in SSM at `/bp/ami/<workflow_slug>`.

### `apply-comfyui-bundle.yml`

**Trigger:** Manual dispatch only (`workflow_dispatch`).

**Purpose:** Sync a chosen bundle to a **running dev GPU instance** via SSM and restart ComfyUI (and the worker).

**Inputs:**
- `instance_id` (required)
- `workflow_slug` (required)
- `bundle_id` (required)
- `models_bucket` (required) — `bp-models-<account>-<stage>`
- `logs_bucket` (optional) — `bp-logs-<account>-<stage>`

**Optional audit logging:**
- Set GitHub secrets `ASSET_OPS_API_URL` and `ASSET_OPS_SECRET` to record a `dev_bundle_applied` event via `POST /api/ops/comfyui-assets/sync-logs`.

### Required GitHub Secrets

| Secret | Description |
|--------|-------------|
| `AWS_DEPLOY_ROLE_ARN` | IAM role ARN for OIDC federation (GitHub Actions → AWS) |
| `PRIVATE_SUBNET_IDS` | JSON array of private subnet IDs (for migration tasks), e.g. `["subnet-123","subnet-456"]` |
| `BACKEND_SG_ID` | Backend security group ID (for migration tasks), e.g. `sg-0123abcd` |
| `ASSET_OPS_API_URL` | (Optional) Backend API base URL for asset ops logging (used by apply bundle workflow) |
| `ASSET_OPS_SECRET` | (Optional) Shared secret for asset ops logging (Secrets Manager `/bp/<stage>/asset-ops/secret`) |

### GitHub Actions to AWS (OIDC)

The CI workflows (`.github/workflows/deploy.yml`, `.github/workflows/build-ami.yml`) authenticate to AWS by assuming an IAM role via GitHub OIDC (no long-lived AWS access keys).

**One-time AWS setup**

1. In AWS IAM, create an OIDC identity provider:
   - **Provider URL**: `https://token.actions.githubusercontent.com`
   - **Audience**: `sts.amazonaws.com`
2. Create an IAM role for **Web identity** (GitHub OIDC) and configure its trust policy to allow this repo.

Example trust policy (replace `<ACCOUNT_ID>` and tighten the `sub` condition as desired):

`<ACCOUNT_ID>` is your AWS account ID (12 digits). You can find it in the AWS Console, or via:

```bash
aws sts get-caller-identity --query Account --output text
```

Make sure the `sub` claim uses the format `repo:<OWNER>/<REPO>:...` (note the `:`), and remove duplicate entries if IAM generated them.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Federated": "arn:aws:iam::<ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com"
      },
      "Action": "sts:AssumeRoleWithWebIdentity",
      "Condition": {
        "StringEquals": {
          "token.actions.githubusercontent.com:aud": "sts.amazonaws.com"
        },
        "StringLike": {
          "token.actions.githubusercontent.com:sub": "repo:maximkartsev/AI-Product-Manager:*"
        }
      }
    }
  ]
}
```

Where to put this policy:
- AWS Console → **IAM → Roles → <your role> → Trust relationships → Edit trust policy**
- This is the role’s **trust policy** (a.k.a. “assume role policy”) that controls **who can assume the role**.

3. Attach **permissions policies** to the role (IAM → Roles → <your role> → Permissions) to control **what AWS actions** the workflows can perform after assuming the role.

Where to attach permissions:
- AWS Console → **IAM → Roles → <your role> → Permissions → Add permissions**
- For `iam:PassRole`, select service **IAM** in the visual editor, or paste JSON in the JSON tab (recommended).

Quick start (broad, staging only): attach **AdministratorAccess** to the role.

Recommended (split by workflow / least privilege, tighten resources later):
- For `.github/workflows/deploy.yml` (ECR + ECS + migrations):
  - `AmazonEC2ContainerRegistryPowerUser`
  - `AmazonECSFullAccess`
  - Plus an `iam:PassRole` policy so `aws ecs run-task` can launch one-off migration tasks (see below)
- For `.github/workflows/build-ami.yml` (Packer AMI build + SSM):
  - `AmazonEC2FullAccess`
  - `AmazonSSMFullAccess` (or restrict to `ssm:PutParameter` on `/bp/ami/*`)
- For `.github/workflows/apply-comfyui-bundle.yml` (SSM + optional logs upload):
  - Allow `ssm:SendCommand` and `ssm:GetCommandInvocation` to the target instance(s)
  - If `logs_bucket` is used, allow `s3:PutObject` to `bp-logs-<account>-<stage>/asset-sync/*`

Example `iam:PassRole` inline policy (tighten `Resource` to your ECS task roles if you know them):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": "iam:PassRole",
      "Resource": "*",
      "Condition": {
        "StringEquals": {
          "iam:PassedToService": "ecs-tasks.amazonaws.com"
        }
      }
    }
  ]
}
```

If you enable the `deploy-infrastructure` job (CDK deploy) in GitHub Actions, the role also needs broad permissions to manage CloudFormation/IAM/networking/etc. If you deploy infrastructure locally, you can leave that job unapproved/unused (or remove it from the workflow).

Tip: tighten the trust policy `sub` claim when you’re ready:
- Any branch/tag: `repo:<owner>/<repo>:*`
- Main only: `repo:<owner>/<repo>:ref:refs/heads/main`

**GitHub setup**

1. Copy the IAM role ARN (IAM → Roles → your role → **ARN**).
2. Add it as a GitHub repo secret named `AWS_DEPLOY_ROLE_ARN` (Repo → Settings → Secrets and variables → Actions).
   - The secret value is the **role ARN**, e.g. `arn:aws:iam::<ACCOUNT_ID>:role/github-actions-staging` (not the OIDC provider ARN).

Notes:
- The workflows already set `permissions: id-token: write` which is required for OIDC.
- If you store `AWS_DEPLOY_ROLE_ARN` as an **Environment** secret, the job must specify `environment: <name>` or the secret will be unavailable and `configure-aws-credentials` may fail with `Could not load credentials from any providers`.
- Separately, attach **permissions policies** to the role (IAM → Roles → <your role> → Permissions) to control **what AWS actions** the workflow can perform after assuming the role (e.g., EC2/SSM for AMI builds).

### Required GitHub Environments

| Environment | Used By | Purpose |
|-------------|---------|---------|
| `staging-infra` | deploy-infrastructure job | Manual approval gate for CDK deploy |
| `staging-migrations` | run-migrations job | Manual approval gate for DB migrations |

## File Map

```
infrastructure/
├── bin/
│   └── app.ts                              # CDK app entry point, stack instantiation
├── lib/
│   ├── config/
│   │   ├── environment.ts                  # Stage configs (staging/production presets)
│   │   └── workflows.ts                    # GPU workflow definitions (slug, AMI, instance types)
│   ├── stacks/
│   │   ├── network-stack.ts                # VPC, subnets, security groups, S3 endpoint
│   │   ├── data-stack.ts                   # RDS, Redis, S3, CloudFront, secrets
│   │   ├── compute-stack.ts                # ECS cluster, ALB, backend/frontend services
│   │   ├── gpu-worker-stack.ts             # Per-workflow GPU ASGs, scale-to-zero
│   │   ├── monitoring-stack.ts             # Dashboard, alarms (P1/P2/P3), SNS
│   │   └── cicd-stack.ts                   # ECR repositories
│   └── constructs/
│       ├── workflow-asg.ts                 # Reusable: ASG + launch template + scaling policies
│       ├── scale-to-zero-lambda.ts         # Shared Lambda: SNS → set ASG desired=0
│       └── rds-init.ts                     # Legacy custom resource (tenant DBs now created via migrations)
├── docker/
│   └── backend/
│       ├── Dockerfile.nginx                # Nginx reverse proxy (ARM64)
│       ├── Dockerfile.php-fpm              # PHP 8.3 FPM + Laravel (ARM64)
│       └── nginx/
│           └── default.conf                # Nginx → PHP-FPM fastcgi config
├── packer/
│   ├── comfyui-worker.pkr.hcl             # AMI template (Ubuntu 22.04, NVIDIA, ComfyUI)
│   ├── variables.pkr.hcl                  # Packer variables (slug, region, instance type)
│   └── scripts/
│       ├── install-nvidia-drivers.sh       # NVIDIA drivers + CUDA
│       ├── install-comfyui.sh              # ComfyUI server setup
│       └── install-python-worker.sh        # Python worker script
├── cdk.json                                # CDK config + context values
├── tsconfig.json                           # TypeScript config
└── package.json                            # CDK dependencies
```
