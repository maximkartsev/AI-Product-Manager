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
          GPU ASG (Spot, per-fleet) ──► ComfyUI workers
                                                             Isolated Subnets
           RDS MariaDB 10.11 ──► bp, tenant_pool_1, tenant_pool_2
           ElastiCache Redis 7.1
```

### Stacks

| Stack | ID | Purpose |
|-------|----|---------|
| **NetworkStack** | `bp-network` | VPC (10.0.0.0/16, 2 AZs), subnets (public/private/isolated), NAT Gateway, 6 security groups, S3 gateway endpoint |
| **DataStack** | `bp-data` | RDS MariaDB 10.11, ElastiCache Redis 7.1, S3 media bucket, S3 logs bucket, CloudFront CDN, secrets (APP_KEY, fleet, OAuth) |
| **ComputeStack** | `bp-compute` | ECS Fargate cluster, ALB (HTTP/HTTPS), backend service (4 containers), frontend service, auto-scaling |
| **GpuSharedStack** | `bp-gpu-shared` | Shared scale-to-zero SNS + Lambda for per-fleet ASGs |
| **GpuFleetStack** | `bp-gpu-fleet-<fleet_stage>-<fleet_slug>` | Per-fleet-stage EC2 ASG (100% Spot), step scaling 0→1, backlog tracking 1→N |
| **MonitoringStack** | `bp-monitoring` | CloudWatch dashboard, P1/P2 alarms, SNS alert topic, optional budget alerts |
| **CiCdStack** | `bp-cicd` | ECR repositories (backend + frontend) with lifecycle policies (keep last 10 images) |

Dependency chain: `Network → Data → Compute → Monitoring`. GPU is `GpuShared → GpuFleet` (provisioned per fleet); CiCd is standalone.

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
# 1. Laravel APP_KEY
php artisan key:generate --show  # run locally to generate
aws secretsmanager put-secret-value \
  --secret-id "/bp/laravel/app-key" \
  --secret-string "base64:YOUR_GENERATED_KEY"

# 2. Fleet secrets (GPU worker registration)
aws ssm put-parameter \
  --name "/bp/fleets/staging/fleet-secret" \
  --value "$(openssl rand -hex 32)" \
  --type String \
  --overwrite
aws ssm put-parameter \
  --name "/bp/fleets/production/fleet-secret" \
  --value "$(openssl rand -hex 32)" \
  --type String \
  --overwrite
# Used by backend (`COMFYUI_FLEET_SECRET_STAGING` + `COMFYUI_FLEET_SECRET_PRODUCTION`) and GPU workers

# 3. OAuth secrets (if using social login)
aws secretsmanager put-secret-value \
  --secret-id "/bp/oauth/secrets" \
  --secret-string '{
    "google_client_id":"...",
    "google_client_secret":"...",
    "tiktok_client_id":"...",
    "tiktok_client_secret":"...",
    "apple_client_id":"...",
    "apple_client_secret":"",
    "apple_key_id":"...",
    "apple_team_id":"...",
    "apple_private_key_p8_b64":"<base64_of_apple_p8_file_bytes>"
  }'
```

#### End-to-end secret sync from `.env` (single system + two fleet stages)

Use the script below to push required values from one app env file into AWS, including:
- Fleet secrets (`/bp/fleets/staging/fleet-secret` and `/bp/fleets/production/fleet-secret`)
- Laravel `APP_KEY` (`/bp/laravel/app-key`)
- OAuth payload (`/bp/oauth/secrets`)
- ECS backend forced redeploy (`bp-backend`)

```powershell
powershell -NoProfile -File .\scripts\sync-env-to-aws.ps1 `
  -Region us-east-1 `
  -EnvPath ..\backend\.env `
  -AppleP8Path C:\secure\keys\apple-production.p8
```

Required env keys in the app env file:
- `APP_KEY`
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`
- `TIKTOK_CLIENT_ID`, `TIKTOK_CLIENT_SECRET`
- `APPLE_CLIENT_ID`, `APPLE_KEY_ID`, `APPLE_TEAM_ID`
- `COMFYUI_FLEET_SECRET_STAGING`, `COMFYUI_FLEET_SECRET_PRODUCTION` (if either is missing, the script generates it and prints a warning)

## Configuration

### Environment Preset (`lib/config/environment.ts`)

The infrastructure now uses a **single system preset** (`SYSTEM_CONFIG`) for non-fleet resources.

Fleet stage (`staging` / `production`) remains only for GPU fleet routing, fleet secrets, and worker metrics dimensions.

Important: GitHub Actions **Environments** are approval/secret scopes; they are independent from fleet stage.

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
- **CI (GitHub Actions)**: IAM **roles** assumed via OIDC (`AWS_DEPLOY_ROLE_ARN_STAGING` / `AWS_DEPLOY_ROLE_ARN_PRODUCTION`). No IAM user keys stored in GitHub.

#### Local AWS CLI for CDK

Preferred: AWS IAM Identity Center (SSO) with an admin/infra permission set.

Quick start (IAM user):
1. AWS Console → **IAM → Users → Create user**
2. On **Set permissions**, choose **Add user to group**, create a group (e.g. `bp-admin`) and attach **AdministratorAccess**.
3. Create an access key for the user and run `aws configure` locally.

CDK needs broad permissions across CloudFormation, IAM, EC2/VPC, ECS, ECR, ELBv2, RDS, ElastiCache, S3, CloudFront, Secrets Manager, SSM, CloudWatch, SNS, Auto Scaling, and Lambda.

#### GitHub Actions OIDC role

See [GitHub Actions to AWS (OIDC)](#github-actions-to-aws-oidc) for the exact trust policy, permissions, and GitHub secrets/environments.

### Fleet templates (new approach)

Fleet templates live in `backend/resources/comfyui/fleet-templates.json`.

- **Backend**: validates `template_slug` + `instance_type` on fleet create/update and writes `/bp/fleets/<fleet_stage>/<fleet_slug>/desired_config` (SSM).
- **Infrastructure**: reads the same templates file (via `lib/config/fleets.ts`) to validate CDK context and apply defaults (max size, warmup, backlog, scale-to-zero).

Creating capacity is a separate, operator-driven step:
- Create the fleet in the Admin UI.
- Run GitHub Actions **Provision GPU Fleet** to create `bp-gpu-fleet-<fleet_stage>-<fleet_slug>`.

## Deployment

### First-Time Deploy

```bash
cd infrastructure && npm ci

export CDK_DEFAULT_ACCOUNT=123456789012
export CDK_DEFAULT_REGION=us-east-1

# 1. Bootstrap CDK
npx cdk bootstrap

# 2. Deploy CI/CD stack first (creates ECR repos)
npx cdk deploy bp-cicd

# 3. Build and push initial Docker images (from repo root)
#    See "Docker Images" section below

# 4. Deploy remaining stacks
npx cdk deploy bp-network bp-data bp-compute bp-monitoring bp-gpu-shared

# 5. Set secrets (see Post-Deploy above)

# 6. Ensure ECR images exist (`bp-backend`:nginx-latest/php-latest, `bp-frontend`:latest)
#    CI build-and-push populates these tags; manual build instructions below.

# 7. Run initial migrations
aws ecs run-task \
  --cluster bp \
  --task-definition bp-backend \
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

Other operational workflows:
- **Deploy Infrastructure** (`deploy-infrastructure.yml`) — CDK deploy (manual gate via GitHub Environment).
- **DB Migrate** (`db-migrate.yml`) — one-off ECS tasks for central + tenant pool migrations (manual gate via GitHub Environment).

### Infrastructure Changes

```bash
cd infrastructure

# Preview what will change
npx cdk diff --all

# Deploy specific stacks
npx cdk deploy bp-data bp-compute

# Deploy everything
npx cdk deploy --all
```

### Database Migrations

Migrations run as one-off ECS tasks (not during normal deploys):

- **ECS service** keeps your app running (backend/frontend tasks stay up behind the ALB).
- **ECS task** is a single, one-off run of a task definition. Here we use `aws ecs run-task` to start a temporary Fargate task and override the `php-fpm` command to run migrations. The task stops when the command finishes.
- These migration tasks run only when you **manually** run the commands below, or when you run the **DB Migrate** workflow (`db-migrate.yml`). They do **not** run during `deploy-services`.

Safety:
- `php artisan migrate` does **not** wipe the database by default, but migrations can still be destructive (e.g. dropping/altering columns). Treat production migrations as potentially risky and ensure you have backups/rollback plans.

Notes:
- `tenancy:pools-migrate` **creates missing tenant pool databases** before applying migrations (uses the central connection).
- Flags: `--central`, `--tenant`, `--fresh`, `--pools=tenant_pool_1,tenant_pool_2`.
- Default behavior runs **both** central and tenant pool migrations.

```bash
# Central database
aws ecs run-task \
  --cluster bp \
  --task-definition bp-backend \
  --overrides '{"containerOverrides":[{"name":"php-fpm","command":["php","artisan","migrate","--force"]}]}' \
  --launch-type FARGATE \
  --network-configuration '{"awsvpcConfiguration":{"subnets":["subnet-xxx"],"securityGroups":["sg-xxx"]}}'

# Tenant pool databases
aws ecs run-task \
  --cluster bp \
  --task-definition bp-backend \
  --overrides '{"containerOverrides":[{"name":"php-fpm","command":["php","artisan","tenancy:pools-migrate","--force"]}]}' \
  --launch-type FARGATE \
  --network-configuration '{"awsvpcConfiguration":{"subnets":["subnet-xxx"],"securityGroups":["sg-xxx"]}}'
```

## GPU Workers

### ComfyUI Assets & Bundles (no S3 duplication)

We store assets as **content-addressed singletons** in S3, and bundles as **manifest-only** records (no copied assets). Bundles are applied by `/opt/comfyui/bin/apply-bundle.sh` during AMI bake, instance boot, or manual SSM apply.

**S3 layout**

- Models bucket: `bp-models-<account>`
- Assets: `assets/<kind>/<sha256>`
- Bundles: `bundles/<bundle_id>/manifest.json`

**Kind → target path mapping**

- `checkpoint` → `models/checkpoints/`
- `lora` → `models/loras/`
- `vae` → `models/vae/`
- `embedding` → `models/embeddings/`
- `controlnet` → `models/controlnet/`
- `custom_node` → `custom_nodes/`
- `other` → `models/other/`

**Manifest schema (stored at `bundles/<bundle_id>/manifest.json`)**

```json
{
  "manifest_version": 1,
  "bundle_id": "uuid",
  "name": "Base SDXL + nodes",
  "created_at": "2026-02-18T12:34:56Z",
  "notes": "Optional notes",
  "assets": [
    {
      "asset_id": 123,
      "kind": "checkpoint",
      "sha256": "abc123...",
      "asset_s3_key": "assets/checkpoint/abc123...",
      "original_filename": "sdxl.safetensors",
      "target_path": "models/checkpoints/sdxl.safetensors",
      "action": "copy"
    }
  ]
}
```

Full schema: `docs/comfyui-assets-bundles-fleets.md`.

**Active bundle pointer**

For each fleet + fleet-stage, the active bundle is stored in SSM:

```
/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle
```

The SSM **value** is the bundle prefix (e.g. `bundles/<bundle_id>`).

**Boot behavior + smart-skip**

- If the AMI was baked with a bundle, Packer writes `/opt/comfyui/.baked_bundle_id`.
- On boot, user-data reads the active bundle prefix and compares it to the baked bundle ID.
- If they match, the instance **skips** asset install.
- If they differ, it runs `/opt/comfyui/bin/apply-bundle.sh` to download the manifest and apply assets.
- The installer records `/opt/comfyui/.active_bundle_id` and `/opt/comfyui/.installed_bundle_paths`.

**Important: AWS S3 vs local MinIO**

- **Assets and bundles live in AWS S3 only** (`bp-models-<account>`). Local MinIO is **dev-only** and not reachable by AWS EC2/SSM/Actions unless you explicitly expose and configure it.
- **Bucket discovery**:
  - CDK outputs (DataStack), or
  - `aws ssm get-parameter --name /bp/models/bucket --query Parameter.Value --output text`
- **Storage config split**:
  - Media uploads use the default `s3` disk (`AWS_*`) → MinIO locally, S3 in staging/prod.
  - ComfyUI models/logs use dedicated disks (`COMFYUI_MODELS_*`, `COMFYUI_LOGS_*`) so models stay in AWS S3 even when media is local.
- **AWS S3 readers**: `bake-ami.yml` (Packer), `apply-comfyui-bundle.yml` (SSM), GPU worker boot (user-data).

#### One-time setup

- **Deploy infrastructure (creates bucket/roles/SSM pointers)**:

```bash
# Example
cd infrastructure
npx cdk deploy bp-network bp-data bp-compute bp-monitoring bp-gpu-shared
```

- **Deploy updated backend/frontend code** (so the new admin endpoints + `/admin/assets` UI exist):
  - See [Updating Application Code (CI/CD)](#updating-application-code-cicd) and run the `Deploy` workflow.

- **Run backend migrations** (creates central DB tables for assets/bundles/audit logs):
  - Via GitHub Actions: run `DB Migrate` (`db-migrate.yml`), or
  - Via CLI (staging example): see [Database Migrations](#database-migrations).

- **Set required secrets** (if not already done):
  - Fleet secrets: `/bp/fleets/staging/fleet-secret` and `/bp/fleets/production/fleet-secret` (SSM)
  - Laravel `APP_KEY`: `/bp/laravel/app-key` (Secrets Manager)

- **Optional: enable GitHub “apply bundle” audit logging**:
  - Read the asset-ops secret:

```bash
aws secretsmanager get-secret-value \
  --secret-id "/bp/asset-ops/secret" \
  --query SecretString --output text
```

  - Store it as GitHub secret `ASSET_OPS_SECRET`
  - Set GitHub secret `ASSET_OPS_API_URL` to your backend API base, e.g. `https://app.example.com/api`

#### Runbook: create → activate → promote

1. **Upload asset files**: open **Admin → Assets** (`/admin/assets`) and upload a model/LoRA/VAE/custom node file.
2. **Create a bundle**: select asset files, enter a bundle name, add notes, click **Create Bundle**.
   - This writes `bundles/<bundle_id>/manifest.json` referencing `assets/<kind>/<sha256>`.
3. **Create a fleet + assign workflows**:
   - Create the fleet in **Admin → Assets → Create Fleet**.
   - Assign workflows in **Fleet Workflow Assignment**.
4. **Activate a bundle for the fleet**:
   - In **Fleets**, pick a bundle and click **Activate**.
   - This updates `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`.
5. **Build / bake AMIs (recommended for stage/prod)**:
   - Run GitHub Actions → **Build Base GPU AMI** (`build-ami.yml`) with:
     - `fleet_slug`
     - `aws_region` (optional)
   - Run GitHub Actions → **Bake GPU AMI (Active Bundle)** (`bake-ami.yml`) with:
     - `fleet_slug`
     - `aws_region` (optional)
   - Both workflows write the AMI alias to `/bp/ami/fleets/<fleet_stage>/<fleet_slug>`.
   - The bake workflow auto-resolves:
     - `models_s3_bucket` from `/bp/models/bucket`
     - `models_s3_prefix` from `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`
     - `packer_instance_profile` from `/bp/packer/instance_profile`
6. **Roll the ASG** so new instances pick up the new AMI + active bundle:
   - AWS Console → Auto Scaling Groups → start **Instance refresh**, or
   - CLI (example):

```bash
aws autoscaling start-instance-refresh \
  --auto-scaling-group-name "asg-<fleet_stage>-<fleet_slug>" \
  --preferences '{"MinHealthyPercentage":90,"InstanceWarmup":300}'
```

   - Scale-to-zero → scale up (for stage), or
   - Replace instances via your standard rollout procedure.

#### Cleanup unused bundles/assets

- **Bundles**: Admin → Assets → **Cleanup Candidates** lists bundles not active in any fleet, with `aws s3 rm` commands and a **Delete** button that removes the S3 prefix + DB record server-side.
- **Assets**: Admin → Assets → **Asset Cleanup Candidates** lists assets not referenced by any bundle, with a **Delete** button that removes the S3 object + DB record server-side.

#### Troubleshooting

- **Boot sync logs**: `/var/log/comfyui-asset-sync.log` on the GPU instance (and `journalctl -u comfyui.service` / `-u comfyui-worker.service`).
- **No active bundle**: the default SSM value is `none`, which skips sync.
- **Wrong AMI in ASG**: verify `/bp/ami/fleets/<fleet_stage>/<fleet_slug>` exists and is `aws:ec2:image`.

### Building AMIs (Packer)

AMIs are built via GitHub Actions:
- **Build Base GPU AMI** (`.github/workflows/build-ami.yml`)
- **Bake GPU AMI (Active Bundle)** (`.github/workflows/bake-ami.yml`)

```
Actions → "Build Base GPU AMI" → Run workflow
  fleet_slug: gpu-default
  aws_region: us-east-1

Actions → "Bake GPU AMI (Active Bundle)" → Run workflow
  fleet_slug: gpu-default
  aws_region: us-east-1
```

The pipeline:
1. Resolves stage from `fleet_slug`
2. Runs `packer build` with NVIDIA drivers, ComfyUI, Python worker
3. Stores the new AMI ID in SSM Parameter Store (`/bp/ami/fleets/<fleet_stage>/<fleet_slug>`)

To build locally:

```bash
cd infrastructure/packer

packer init .
packer build \
  -var "fleet_slug=gpu-default" \
  -var "instance_type=g4dn.xlarge" \
  -var "aws_region=us-east-1" \
  -var "models_s3_bucket=bp-models-<account>" \
  -var "models_s3_prefix=bundles/<bundle_id>" \
  -var "bundle_id=<bundle_id>" \
  .
```

Packer provisioning steps:
1. `install-nvidia-drivers.sh` — NVIDIA GPU drivers + CUDA
2. `install-comfyui.sh` — ComfyUI server at `/opt/comfyui`
3. `install-python-worker.sh` — Worker script at `/opt/worker`
4. Bundle apply from S3 (optional, if `models_s3_bucket` + `models_s3_prefix` are set)

### Apply Bundle to Dev Instance (manual)

Use `.github/workflows/apply-comfyui-bundle.yml` to sync a bundle onto a **running dev GPU instance**:

**Inputs:**
- `instance_id` — EC2 instance ID
- `fleet_slug` — e.g. `gpu-default`
- `logs_bucket` — optional, `bp-logs-<account>`

**Requirements:**
- The dev instance must have **SSM** enabled and an instance profile with **S3 read** to the models bucket.
- The workflow uses `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`.
- The workflow will upload SSM command output to the logs bucket (if provided).
- If `ASSET_OPS_API_URL` + `ASSET_OPS_SECRET` are set, the workflow also records an audit log via the backend.

### Adding a New Fleet (new approach)

1. Ensure a suitable template exists in `backend/resources/comfyui/fleet-templates.json`.
2. Create the fleet in the Admin UI (template + instance type). This writes `/bp/fleets/<fleet_stage>/<fleet_slug>/desired_config`.
3. Run GitHub Actions → **Provision GPU Fleet** to create `bp-gpu-fleet-<fleet_stage>-<fleet_slug>`.
4. If `/bp/ami/fleets/<fleet_stage>/<fleet_slug>` is missing, run **Build Base GPU AMI**.
5. Activate the bundle in the Admin UI, then run **Bake GPU AMI (Active Bundle)** and refresh the ASG.

### Monitoring Workers

Custom CloudWatch metrics (namespace: `ComfyUI/Workers`, dimensions: `FleetSlug` + `Stage`):
- `QueueDepth` — pending jobs for this fleet
- `BacklogPerInstance` — jobs per active worker
- `ActiveWorkers` — running instances
- `AvailableCapacity` — sum of available worker slots
- `JobProcessingP50` — median job duration
- `ErrorRate` — % of failed jobs
- `LeaseExpiredCount` — count of expired leases
- `SpotInterruptionCount` — Spot reclamations

The ADR-0005 contract is fleet-only: workflow-dimension CloudWatch metrics are not emitted.

### Scale-to-Zero Behavior

1. Queue empties → `QueueDepth == 0` for 15 min (configurable via `scaleToZeroMinutes`)
2. CloudWatch alarm triggers → SNS → Lambda
3. Lambda sets ASG `DesiredCapacity = 0`
4. New job arrives → `QueueDepth > 0` alarm → step scaling policy sets capacity to 1
5. More jobs → target tracking scales 1→N based on `BacklogPerInstance` and `fleet.backlogTarget`

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
  - Backend repo: `bp-backend`
    - `nginx-latest`
    - `php-latest`
  - Frontend repo: `bp-frontend`
    - `latest`
    - `<sha7>` (CI tag)
  - CI `deploy.yml` builds and pushes these tags on every `main` push.

- **GPU AMI builds**:
  - Trigger GitHub Actions `build-ami.yml` (Base) or `bake-ami.yml` (Active Bundle).
  - Inputs: `fleet_slug`, `aws_region` (optional).
  - Output: AMI ID written to SSM at `/bp/ami/fleets/<fleet_stage>/<fleet_slug>`.
  - Bake auto-resolves models bucket, active bundle prefix, and packer instance profile from SSM.

## Secrets Reference

| Secret | Store | Path | Auto-Generated? | Manual Setup |
|--------|-------|------|-----------------|--------------|
| RDS master credentials | Secrets Manager | `/bp/rds/master-credentials` | Yes (CDK) | — |
| Redis AUTH token | Secrets Manager | `/bp/redis/auth-token` | Yes (CDK) | — |
| Laravel APP_KEY | Secrets Manager | `/bp/laravel/app-key` | No (placeholder) | `aws secretsmanager put-secret-value --secret-id /bp/laravel/app-key --secret-string "base64:..."` |
| Fleet secret (staging) | SSM Parameter Store | `/bp/fleets/staging/fleet-secret` | No (`CHANGE_ME_AFTER_DEPLOY`) | `aws ssm put-parameter --name /bp/fleets/staging/fleet-secret --value "..." --type String --overwrite` |
| Fleet secret (production) | SSM Parameter Store | `/bp/fleets/production/fleet-secret` | No (`CHANGE_ME_AFTER_DEPLOY`) | `aws ssm put-parameter --name /bp/fleets/production/fleet-secret --value "..." --type String --overwrite` |
| Asset ops secret | Secrets Manager | `/bp/asset-ops/secret` | Yes (CDK) | Use the secret value as `ASSET_OPS_SECRET` in GitHub Actions |
| Models bucket | SSM Parameter Store | `/bp/models/bucket` | Yes (CDK) | — |
| Packer instance profile | SSM Parameter Store | `/bp/packer/instance_profile` | Yes (CDK) | Used by AMI bake workflow |
| OAuth secrets | Secrets Manager | `/bp/oauth/secrets` | No (placeholder) | `aws secretsmanager put-secret-value --secret-id /bp/oauth/secrets --secret-string '{"google_client_id":"...","google_client_secret":"...","tiktok_client_id":"...","tiktok_client_secret":"...","apple_client_id":"...","apple_client_secret":"","apple_key_id":"...","apple_team_id":"...","apple_private_key_p8_b64":"..."}'` |
| GPU AMI IDs | SSM Parameter Store | `/bp/ami/fleets/<fleet_stage>/<fleet_slug>` | Yes (Packer CI) | `aws ssm put-parameter --name /bp/ami/fleets/<fleet_stage>/<slug> --value ami-xxx --data-type "aws:ec2:image" --type String --overwrite` |
| Active asset bundle | SSM Parameter Store | `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle` | Yes (CDK, default: `none`) | Set via **Admin → Assets**, or `aws ssm put-parameter --name /bp/fleets/<fleet_stage>/<slug>/active_bundle --value "bundles/<bundle_id>" --type String --overwrite` |
| Redis endpoint | SSM Parameter Store | `/bp/redis/endpoint` | Yes (CDK) | — |

## Monitoring & Alerts

### Dashboard

Name: `bp`

| Row | Widgets |
|-----|---------|
| 1 — App Health | ALB Requests & 5xx Errors, ALB Latency (p50/p95/p99) |
| 2 — Data Layer | RDS CPU & Connections, RDS Free Storage |
| 3 — NAT Gateway | NAT Bytes In/Out, Port Allocation Errors |
| 4+ — GPU Workers | Per-fleet: Queue Depth & Workers, Performance & Errors |

### Log Groups & Retention

- Backend: `/ecs/bp-backend`
- Frontend: `/ecs/bp-frontend`
- GPU workers: `/gpu-workers/<fleet-slug>`

Retention:
- **System logs**: 30 days

### Alarm Tiers

| Tier | Alarm | Threshold | Period |
|------|-------|-----------|--------|
| **P1** | ALB 5xx Critical | ≥ 50 errors | 5 min |
| **P1** | RDS CPU Critical | ≥ 95% | 10 min |
| **P1** | RDS Storage Low | < 2 GB free | 5 min |
| **P2** | ALB 5xx Warning | ≥ 10 errors | 5 min |
| **P2** | RDS CPU Warning | ≥ 80% | 5 min |
| **P2** | Per-fleet Error Rate | ≥ 20% | 5 min |
| **P3** | Per-fleet Queue Deep | ≥ 10 jobs | 30 min |

### SNS Topic

Topic name: `bp-ops-alerts`. Subscribes `ALERT_EMAIL` if configured.

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
aws ecs update-service --cluster bp --service bp-backend --desired-count 0
aws ecs update-service --cluster bp --service bp-frontend --desired-count 0
```

2. Scale GPU ASGs to zero (repeat for each fleet slug):

```bash
aws autoscaling update-auto-scaling-group \
  --auto-scaling-group-name asg-<fleet_stage>-<fleet_slug> \
  --min-size 0 --max-size 0 --desired-capacity 0
```

3. Verify no running tasks/instances:

```bash
aws ecs describe-services \
  --cluster bp \
  --services bp-backend bp-frontend \
  --query 'services[].{name:serviceName,running:runningCount,desired:desiredCount}'

aws autoscaling describe-auto-scaling-groups \
  --auto-scaling-group-names asg-<fleet_stage>-<fleet_slug> \
  --query 'AutoScalingGroups[].{name:AutoScalingGroupName,desired:DesiredCapacity,instances:Instances[].InstanceId}'
```

### Full teardown (destroy infrastructure)

For a full “wipe and redeploy” runbook, see: `docs/recreate-infrastructure.md`.

```bash
cd infrastructure
# Per-fleet stacks (repeat for each fleet-stage + slug)
npx cdk destroy bp-gpu-fleet-<fleet_stage>-<fleet_slug>

# Shared + core stacks
npx cdk destroy bp-gpu-shared bp-monitoring bp-compute bp-data bp-network bp-cicd
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
| Backend returns 502 | PHP-FPM container not ready or crashed | Check `/ecs/bp-backend` logs, verify DB connectivity |
| GPU workers not starting | AMI not found or SSM parameter missing | Verify SSM parameter `/bp/ami/fleets/<fleet_stage>/<fleet_slug>` has a valid AMI ID |
| Scale-to-zero not working | Lambda not triggered | Check alarm state, verify SNS subscription, check Lambda logs |
| ALB returns 404 | No healthy targets | Check target group health, verify ECS service desired count > 0 |
| CDK deploy fails on secrets | Secret already exists | Import with `cdk import` or delete manually from Secrets Manager |
| RDS connection refused | Security group misconfigured | Verify `sgRds` allows port 3306 from `sgBackend` |

### Useful Commands

```bash
# ECS Exec into running backend container
aws ecs execute-command \
  --cluster bp \
  --task <task-id> \
  --container php-fpm \
  --interactive \
  --command "/bin/bash"

# Tail backend logs
aws logs tail /ecs/bp-backend --follow

# Tail GPU worker logs
aws logs tail /gpu-workers/<fleet_slug> --follow

# Check ECS service status
aws ecs describe-services \
  --cluster bp \
  --services bp-backend bp-frontend \
  --query 'services[].{name:serviceName,status:status,running:runningCount,desired:desiredCount}'

# Check GPU ASG status
aws autoscaling describe-auto-scaling-groups \
  --auto-scaling-group-names asg-<fleet_stage>-<fleet_slug> \
  --query 'AutoScalingGroups[].{name:AutoScalingGroupName,desired:DesiredCapacity,min:MinSize,max:MaxSize,instances:Instances[].InstanceId}'

# Force new deployment (pick up latest image)
aws ecs update-service --cluster bp --service bp-backend --force-new-deployment

# Check CloudWatch alarm states
aws cloudwatch describe-alarms \
  --alarm-name-prefix p \
  --query 'MetricAlarms[].{name:AlarmName,state:StateValue}'
```

## CI/CD Reference

### `deploy.yml`

**Trigger:** Manual dispatch only (`workflow_dispatch`).

```
test-backend ──┐
               ├──► build-and-push ──► deploy-services
test-frontend ─┘
```

### `build-ami.yml` (Build Base GPU AMI)

**Trigger:** Manual dispatch only (`workflow_dispatch`).

**Inputs:**
- `fleet_slug` (required) — e.g. `gpu-default`
- `aws_region` (optional)
- `start_instance_refresh` (optional)

**Output:** AMI ID stored in SSM at `/bp/ami/fleets/<fleet_stage>/<fleet_slug>`.

### `bake-ami.yml` (Bake GPU AMI - Active Bundle)

**Trigger:** Manual dispatch only (`workflow_dispatch`).

**Inputs:**
- `fleet_slug` (required)
- `aws_region` (optional)
- `start_instance_refresh` (optional)

**Auto-resolves:**
- models bucket from `/bp/models/bucket`
- active bundle from `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`
- packer instance profile from `/bp/packer/instance_profile`

**Output:** AMI ID stored in SSM at `/bp/ami/fleets/<fleet_stage>/<fleet_slug>`.

### `apply-comfyui-bundle.yml`

**Trigger:** Manual dispatch only (`workflow_dispatch`).

**Purpose:** Sync a chosen bundle to a **running dev GPU instance** via SSM and restart ComfyUI (and the worker).

**Inputs:**
- `instance_id` (required)
- `fleet_slug` (required)
- `logs_bucket` (optional) — `bp-logs-<account>`

**Optional audit logging:**
- Set GitHub secrets `ASSET_OPS_API_URL` and `ASSET_OPS_SECRET` to record a `dev_bundle_applied` event via `POST /api/ops/comfyui-assets/sync-logs`.

### Required GitHub Secrets

| Secret | Description |
|--------|-------------|
| `AWS_DEPLOY_ROLE_ARN_STAGING` | IAM role ARN for OIDC federation (GitHub Actions → AWS, staging) |
| `AWS_DEPLOY_ROLE_ARN_PRODUCTION` | IAM role ARN for OIDC federation (GitHub Actions → AWS, production) |
| `PRIVATE_SUBNET_IDS` | (Optional) JSON array of private subnet IDs for one-off ECS tasks (db-migrate/db-seed), e.g. `["subnet-123","subnet-456"]` |
| `BACKEND_SG_ID` | (Optional) Backend security group ID for one-off ECS tasks (db-migrate/db-seed), e.g. `sg-0123abcd` |
| `ASSET_OPS_API_URL` | (Optional) Backend API base URL for asset ops logging (used by apply bundle workflow) |
| `ASSET_OPS_SECRET` | (Optional) Shared secret for asset ops logging (Secrets Manager `/bp/asset-ops/secret`) |

### GitHub Actions to AWS (OIDC)

The CI workflows (`.github/workflows/deploy.yml`, `.github/workflows/build-ami.yml`, `.github/workflows/bake-ami.yml`) authenticate to AWS by assuming an IAM role via GitHub OIDC (no long-lived AWS access keys).

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
- For `.github/workflows/build-ami.yml` / `.github/workflows/bake-ami.yml` (Packer AMI build + SSM):
  - `AmazonEC2FullAccess`
  - `AmazonSSMFullAccess` (or restrict to `ssm:PutParameter` on `/bp/ami/*`)
  - `iam:PassRole` for the instance profile in `/bp/packer/instance_profile`
- For `.github/workflows/apply-comfyui-bundle.yml` (SSM + optional logs upload):
  - Allow `ssm:SendCommand` and `ssm:GetCommandInvocation` to the target instance(s)
  - If `logs_bucket` is used, allow `s3:PutObject` to `bp-logs-<account>/asset-sync/*`
- For `.github/workflows/create-dev-gpu-instance.yml` (EC2 + SSM):
  - Allow `ec2:RunInstances`, `ec2:CreateSecurityGroup`, `ec2:AuthorizeSecurityGroupIngress`, `ec2:Describe*`
  - `iam:PassRole` for the instance profile used by the dev instance

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
2. Add GitHub repo (or Environment) secrets:
   - `AWS_DEPLOY_ROLE_ARN_STAGING` — role ARN for staging deploys
   - `AWS_DEPLOY_ROLE_ARN_PRODUCTION` — role ARN for production deploys (separate AWS account recommended)
   - Secret values are **role ARNs**, e.g. `arn:aws:iam::<ACCOUNT_ID>:role/github-actions-staging` (not the OIDC provider ARN).

Notes:
- The workflows already set `permissions: id-token: write` which is required for OIDC.
- If you store these as **Environment** secrets, the job must specify `environment: <name>` or the secrets will be unavailable and `configure-aws-credentials` may fail with `Could not load credentials from any providers`.
- Separately, attach **permissions policies** to the role (IAM → Roles → <your role> → Permissions) to control **what AWS actions** the workflow can perform after assuming the role (e.g., EC2/SSM for AMI builds).

### Required GitHub Environments

| Environment | Used By | Purpose |
|-------------|---------|---------|
| `staging-infra` | `deploy-infrastructure.yml` | Manual approval gate for CDK deploy (staging) |
| `production-infra` | `deploy-infrastructure.yml` | Manual approval gate for CDK deploy (production) |
| `staging-migrations` | `db-migrate.yml`, `db-seed.yml` | Manual approval gate for DB tasks (staging) |
| `production-migrations` | `db-migrate.yml`, `db-seed.yml` | Manual approval gate for DB tasks (production) |
| `staging-gpu-provision` | `provision-gpu-fleet.yml`, `apply-gpu-fleet-config.yml` | Manual approval gate for GPU fleet stack deploys (staging) |
| `production-gpu-provision` | `provision-gpu-fleet.yml`, `apply-gpu-fleet-config.yml` | Manual approval gate for GPU fleet stack deploys (production) |

## File Map

```
infrastructure/
├── bin/
│   └── app.ts                              # CDK app entry point, stack instantiation
├── lib/
│   ├── config/
│   │   ├── environment.ts                  # Single-system config preset
│   │   └── fleets.ts                       # Fleet templates loader + helpers (reads backend/resources/comfyui/fleet-templates.json)
│   ├── stacks/
│   │   ├── network-stack.ts                # VPC, subnets, security groups, S3 endpoint
│   │   ├── data-stack.ts                   # RDS, Redis, S3, CloudFront, secrets
│   │   ├── compute-stack.ts                # ECS cluster, ALB, backend/frontend services
│   │   ├── gpu-shared-stack.ts             # Shared scale-to-zero SNS + Lambda
│   │   ├── gpu-fleet-stack.ts              # Single-fleet GPU ASG stack (bp-gpu-fleet-<fleet_stage>-<fleet_slug>)
│   │   ├── monitoring-stack.ts             # Dashboard, alarms (P1/P2), SNS
│   │   └── cicd-stack.ts                   # ECR repositories
│   └── constructs/
│       ├── fleet-asg.ts                    # Reusable: ASG + launch template + scaling policies
│       └── rds-init.ts                     # Legacy custom resource (tenant DBs now created via migrations)
├── docker/
│   └── backend/
│       ├── Dockerfile.nginx                # Nginx reverse proxy (ARM64)
│       ├── Dockerfile.php-fpm              # PHP 8.3 FPM + Laravel (ARM64)
│       └── nginx/
│           └── default.conf                # Nginx → PHP-FPM fastcgi config
├── packer/
│   ├── comfyui-worker.pkr.hcl             # AMI template (Ubuntu 22.04, NVIDIA, ComfyUI)
│   ├── variables.pkr.hcl                  # Packer variables (fleet slug, region, instance type)
│   └── scripts/
│       ├── install-nvidia-drivers.sh       # NVIDIA drivers + CUDA
│       ├── install-comfyui.sh              # ComfyUI server setup
│       ├── install-python-worker.sh        # Python worker script
│       └── apply-bundle.sh                 # Manifest-driven bundle installer
├── cdk.json                                # CDK config + context values
├── tsconfig.json                           # TypeScript config
└── package.json                            # CDK dependencies
```
