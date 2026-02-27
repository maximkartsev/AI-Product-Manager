# GPU Fleet Operations Runbook

Production-ready administrator documentation for creating ComfyUI workflows, uploading required assets, packaging them into bundles, deploying them to a shared GPU fleet ASG, and verifying end-to-end processing in staging and production.

## Overview

This system uses:
- **Content-addressed assets** in S3: `assets/<kind>/<sha256>`
- **Manifest-only bundles** in S3: `bundles/<bundle_id>/manifest.json`
- **Shared GPU fleet** (e.g., `gpu-default`) that can run many workflows
- **Active bundle pointers** in SSM: `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`
- **Desired fleet config** in SSM: `/bp/fleets/<fleet_stage>/<fleet_slug>/desired_config`

Workers boot from a fleet AMI, apply the active bundle, then self-register to the backend and pull jobs.

## Preconditions and environment setup

### AWS infrastructure

Ensure the following are deployed:
- `bp-data` (models/logs buckets + SSM pointers)
- `bp-compute` (backend/frontend services)
- `bp-gpu-shared` (scale-to-zero SNS + Lambda)
- `bp-gpu-fleet-<fleet_stage>-<fleet_slug>` (per-fleet ASG + user-data)

#### Check that the stacks exist

These are **CloudFormation stacks** created by CDK.

CLI example:

```bash
FLEET_STAGE=staging
FLEET_SLUG=gpu-default

# Sanity check: correct AWS account/region
aws sts get-caller-identity --query Account --output text
aws configure get region

# Check each required stack directly (prints StackStatus; errors if missing)
aws cloudformation describe-stacks --stack-name "bp-data" --query "Stacks[0].StackStatus" --output text
aws cloudformation describe-stacks --stack-name "bp-compute" --query "Stacks[0].StackStatus" --output text
aws cloudformation describe-stacks --stack-name "bp-gpu-shared" --query "Stacks[0].StackStatus" --output text
aws cloudformation describe-stacks --stack-name "bp-gpu-fleet-${FLEET_STAGE}-${FLEET_SLUG}" --query "Stacks[0].StackStatus" --output text
```

Optional (requires `cloudformation:ListStacks` permission):

```bash
aws cloudformation list-stacks \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-')].StackName" \
  --output table
```

If you get `AccessDenied` for CloudFormation APIs, you need additional IAM permissions (at least `cloudformation:DescribeStacks` to check, and broad CloudFormation + IAM permissions to deploy via CDK). If you don't control IAM, use the AWS Console or assume the deployment role used for CDK/CI.

AWS Console:
- Go to **CloudFormation → Stacks**
- Search for: `bp-data`, `bp-compute`, `bp-gpu-shared`, and `bp-gpu-fleet-<fleet_stage>-<fleet_slug>`

#### If a stack is missing: deploy it (or redeploy)

Stacks have dependencies: `bp-network` → `bp-data` → `bp-compute` → `bp-gpu-shared` (monitoring is recommended).

Deploy the chain (from repo root):

```bash
cd infrastructure
npm install

# One-time per AWS account/region:
npx cdk bootstrap

# Deploy required stacks (recommended order)
npx cdk deploy \
  bp-network \
  bp-data \
  bp-compute \
  bp-gpu-shared

Per-fleet stacks (`bp-gpu-fleet-<fleet_stage>-<fleet_slug>`) are provisioned via GitHub Actions (see Step 9 below).
```

If you want *all* stacks (including monitoring/cicd), you can deploy everything:

```bash
cd infrastructure
npx cdk deploy --all
```

#### Common blocker: CDK bootstrap/deploy permissions

`cdk bootstrap` and `cdk deploy` require **broad AWS permissions** (CloudFormation + IAM + ECR + S3 + SSM, etc.). If you see `AccessDenied` errors like:
- `ecr:CreateRepository`
- `iam:GetRole` / `iam:CreateRole` / `iam:AttachRolePolicy` / `iam:DeleteRole`
- `ssm:PutParameter`

…then your current AWS identity cannot bootstrap/deploy this account. Use an **admin/infra** role (preferred: AWS SSO/Identity Center admin permission set), or ask your platform team to bootstrap the account/region once.

If `cdk bootstrap` fails and leaves a `CDKToolkit` stack in `ROLLBACK_FAILED`, an admin must delete it in **CloudFormation** (and may need to manually delete leftover `cdk-hnb659fds-*` IAM roles/repositories before retrying).

#### Container images (ECR) must exist before deploying `bp-compute`

The `bp-compute` stack creates ECS services that reference **ECR images** by tag:

- Backend repo `bp-backend`:
  - `nginx-latest`
  - `php-latest`
- Frontend repo `bp-frontend`:
  - `latest`

If those tags are missing, ECS cannot pull containers and you’ll see `ECS Deployment Circuit Breaker was triggered` while creating the services.

##### Frontend → Backend connectivity (API base URL)

The frontend calls the backend over HTTP using `NEXT_PUBLIC_API_BASE_URL` (or `NEXT_PUBLIC_API_URL`) from `frontend/src/lib/api.ts`.

In AWS, the ALB routes:
- `/api/*` → backend
- `/*` → frontend

So the frontend must use an API base that includes `/api`:
- Absolute: `https://<your-domain>/api`
- Or (recommended for this ALB topology): **`/api`** (same-origin)

Important: `NEXT_PUBLIC_*` variables are **baked into the browser bundle at build time**. Setting them only on the ECS task definition is not enough if the image was built with a different value (for example, a committed `.env.local`).

This repo’s production frontend Docker build sets `NEXT_PUBLIC_API_BASE_URL=/api` and removes `.env.local` during the image build, so the deployed UI talks to the backend automatically through the ALB.

Check whether the required tags exist:

```bash
aws ecr describe-images \
  --repository-name "bp-backend" \
  --query "imageDetails[].imageTags" \
  --output json

aws ecr describe-images \
  --repository-name "bp-frontend" \
  --query "imageDetails[].imageTags" \
  --output json
```

If the repositories don’t exist at all, deploy the ECR stack first:

```bash
cd infrastructure
npx cdk deploy bp-cicd
```

##### Populate ECR via GitHub Actions (recommended)

This repo includes a manual GitHub Actions workflow: `.github/workflows/deploy.yml` (Actions → **Deploy**).

What it does (high level):
- **test-backend**: runs `php artisan test`
- **test-frontend**: runs `pnpm build`
- **build-and-push**: builds `linux/arm64` images and pushes the required tags to ECR
- **deploy-services**: runs ECS deployments (`update-service --force-new-deployment`)

Related workflows:
- **Deploy Infrastructure**: `.github/workflows/deploy-infrastructure.yml`
- **DB Migrate**: `.github/workflows/db-migrate.yml`

How to run it:
1. GitHub → **Actions** → **Deploy**
2. Click **Run workflow**
3. Wait for **build-and-push** to complete
4. Verify ECR tags exist (commands above)
5. Deploy `bp-compute` (CDK) or let the workflow deploy services

##### `AWS_DEPLOY_ROLE_ARN_STAGING` / `AWS_DEPLOY_ROLE_ARN_PRODUCTION` (GitHub Actions → AWS)

These are the IAM roles that GitHub Actions assumes using OIDC (no long-lived AWS keys).

Where to configure it in GitHub:
- Repository → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**
  - Name: `AWS_DEPLOY_ROLE_ARN_STAGING` (and `AWS_DEPLOY_ROLE_ARN_PRODUCTION` if you deploy production)
  - Value: `arn:aws:iam::<ACCOUNT_ID>:role/<ROLE_NAME>`

Important: jobs like **build-and-push** and **deploy-services** do **not** specify a GitHub Environment, so these must be **repository secrets** (not only environment-scoped secrets) unless you update the workflows to attach an environment to those jobs.

How to configure the role in AWS (outline):
1. Create/verify the GitHub OIDC provider exists in IAM:
   - Provider URL: `token.actions.githubusercontent.com`
   - Audience: `sts.amazonaws.com`
2. Create an IAM role with:
   - Trusted entity: **Web identity**
   - Identity provider: GitHub OIDC provider above
   - A trust policy that restricts to this repo (example):

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
          "token.actions.githubusercontent.com:sub": [
            "repo:<ORG>/<REPO>:ref:refs/heads/main",
            "repo:<ORG>/<REPO>:environment:staging-infra",
            "repo:<ORG>/<REPO>:environment:staging-migrations"
          ]
        }
      }
    }
  ]
}
```

Permissions the role needs depend on which jobs you run:
- **Build & push images** (`build-and-push`): ECR push permissions (plus `ecr:GetAuthorizationToken`)
- **Deploy services** (`deploy-services`): `ecs:UpdateService`, `ecs:Describe*` (and related)
- **Deploy infrastructure** (`deploy-infrastructure`): broad permissions across CloudFormation + IAM + VPC/EC2 + ECS + ECR + RDS + S3 + CloudFront + SSM + Secrets Manager + CloudWatch + SNS + Auto Scaling + Lambda

For staging, the simplest starting point is attaching **`AdministratorAccess`** to the role; tighten later if needed.

#### Post-deploy readiness checks (backend + frontend)

After `bp-compute` is deployed and images exist, use this checklist to confirm the app is actually usable.

##### 1) Confirm ECS services are stable

```bash
aws ecs wait services-stable \
  --cluster "bp" \
  --services "bp-backend" "bp-frontend"
```

##### 2) Confirm the ALB routes are healthy (`/up` and `/`)

Get the base URL from the stack output:

```bash
API_BASE_URL=$(aws cloudformation describe-stacks \
  --stack-name "bp-compute" \
  --query "Stacks[0].Outputs[?OutputKey=='ApiBaseUrl'].OutputValue" \
  --output text)
echo "$API_BASE_URL"
```

Then verify:

```bash
curl -i "${API_BASE_URL}/up"
curl -I "${API_BASE_URL}/"
```

Expected:
- `/up` returns `200 OK`
- `/` returns `200 OK` (HTML)

##### 3) Check CloudWatch logs for crashes

```bash
aws logs tail "/ecs/bp-backend" --follow
aws logs tail "/ecs/bp-frontend" --follow
```

Common fatal misconfigurations you’ll see here:
- Missing/invalid `APP_KEY`
- DB connectivity issues
- Missing ECR tags or wrong image architecture (should be `linux/arm64`)

##### 4) Required one-time settings after first deploy

Even if stacks deploy, the app may not function until these are set:

- **Laravel `APP_KEY`** (Secrets Manager): `/bp/laravel/app-key`
  - Must be a Laravel key string like `base64:...`
- **OAuth secrets** (Secrets Manager): `/bp/oauth/secrets`
- **Fleet secret** (SSM):
  - Staging: `/bp/fleets/staging/fleet-secret`
  - Production: `/bp/fleets/production/fleet-secret`
  - Must not be `CHANGE_ME_AFTER_DEPLOY`

##### 5) Database migrations (required)

You must run both:
- Central migrations: `php artisan migrate --force`
- Tenant pool migrations: `php artisan tenancy:pools-migrate`

Recommended: run the GitHub Actions **Deploy → Run Migrations** job (it uses `aws ecs run-task`).

To use that job you must set these GitHub **Environment** secrets for `staging-migrations`:
- `PRIVATE_SUBNET_IDS`: JSON array like `["subnet-...","subnet-..."]` (private subnets)
- `BACKEND_SG_ID`: the backend service security group id like `sg-...`

Manual alternative (CLI) is possible but requires you to provide the same subnet + SG values to `aws ecs run-task`.

##### 6) Database seeding (staging only, optional)

To seed staging data:
- Full seed: `php artisan db:seed --force` (runs `DatabaseSeeder`)
- Single seeder: `php artisan db:seed --force --class=UsersSeeder` (or a fully-qualified class)

Recommended: run the GitHub Actions **DB Seed** workflow (`.github/workflows/db-seed.yml`). It uses the same `staging-migrations` environment secrets as the migrations job.

Check required SSM parameters:

```bash
aws ssm get-parameter --name /bp/models/bucket --query Parameter.Value --output text
aws ssm get-parameter --name /bp/fleets/staging/fleet-secret --query Parameter.Value --output text
aws ssm get-parameter --name /bp/fleets/production/fleet-secret --query Parameter.Value --output text
```

If a fleet secret is still the placeholder:

```bash
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
```

### Backend configuration

In AWS ECS, these are injected automatically (see `infrastructure/lib/stacks/compute-stack.ts`):
- `COMFYUI_MODELS_BUCKET`, `COMFYUI_MODELS_DISK=comfyui_models`
- `COMFYUI_LOGS_BUCKET`, `COMFYUI_LOGS_DISK=comfyui_logs`
- `COMFYUI_FLEET_SECRET_STAGING` from `/bp/fleets/staging/fleet-secret`
- `COMFYUI_FLEET_SECRET_PRODUCTION` from `/bp/fleets/production/fleet-secret`

For local admin use, follow `quickstart.md`:
- Media uploads may use MinIO via `AWS_*`
- ComfyUI models/logs should use **real AWS S3** via `COMFYUI_MODELS_*` / `COMFYUI_LOGS_*`

## Step-by-step execution (UI + console)

### Step 1: Create a Dev GPU instance (GitHub Actions)

This gives you a live ComfyUI UI for iterating on workflows.

Run GitHub Action: **Create Dev GPU Instance** (`.github/workflows/create-dev-gpu-instance.yml`)

Inputs:
- `fleet_slug`
- `fleet_stage`
- `allowed_cidr` (your IP/CIDR for port 8188)
- `aws_region` (optional)
- `auto_shutdown_hours` (optional)

Notes:
- The action uses explicit `fleet_stage`, pulls the AMI from `/bp/ami/fleets/<fleet_stage>/<fleet_slug>`, and uses the fleet’s `desired_config` instance type.
- If that AMI parameter doesn’t exist yet, run **Build Base GPU AMI** first.

Outcome: the workflow summary prints the ComfyUI URL `http://<public-ip>:8188`.

### Step 2: Load and run the workflow in ComfyUI

1. Load the workflow JSON into the ComfyUI UI.
2. Run the workflow.
3. Note any missing assets (checkpoints, LoRAs, VAEs, embeddings, controlnet, custom nodes).

### Step 3: Upload missing assets via Admin UI

Path: **Admin → ComfyUI → Assets** (`/admin/comfyui/assets`)

For each asset:
- Choose **Kind**: `checkpoint | lora | vae | embedding | controlnet | custom_node | other`
- Upload the file
- Optional: add notes

The UI computes SHA-256 and uploads to S3, then creates or updates the asset record.

### Step 4: Create a bundle (manifest) in Admin UI

Path: **Admin → ComfyUI → Bundles** (`/admin/comfyui/bundles`)

1. Click **Create Bundle**
2. Select the asset files needed by the workflow
3. Optional overrides:
   - `target_path` (relative to `/opt/comfyui/`)
   - `action` (`copy`, `extract_zip`, `extract_tar_gz`) for archives (custom nodes)
   - Examples:
     - `copy`: `models/checkpoints/sdxl.safetensors`
     - `extract_zip`: `custom_nodes/ComfyUI-Manager` (archive extracts into that folder)

The backend writes `bundles/<bundle_id>/manifest.json` to the models bucket.

### Step 5: Apply the bundle to the Dev GPU node

Recommended: GitHub Action `.github/workflows/apply-comfyui-bundle.yml`

Inputs:
- `instance_id`: dev GPU EC2 instance ID
- `fleet_slug`: e.g. `gpu-default`
- `fleet_stage`: `staging` or `production`
- `logs_bucket` (optional): `bp-logs-<account>`
- `notes` (optional)
- `aws_region` (optional)

This action reads the models bucket from `/bp/models/bucket` and uses the active bundle pointer in `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`.
Make sure the fleet’s active bundle is set in the Admin UI before running it.

It runs SSM to execute:
`MODELS_BUCKET=... BUNDLE_PREFIX=... /opt/comfyui/bin/apply-bundle.sh`

Re-test the workflow in ComfyUI to confirm missing assets are resolved.

### Step 6: Export ComfyUI API JSON

Export the workflow as **API JSON** (the JSON you send to `/prompt`).
This is the file the workers will execute.

### Step 7: Create the workflow in Admin UI

Path: **Admin → Workflows** (`/admin/workflows`)

Required fields:
- `name`, `slug`, `description`
- `is_active = true`
- `comfyui_workflow_path` (S3 path of API JSON)
- `output_node_id` (node that produces the output)
- `output_extension` (usually `mp4`)
- `output_mime_type` (usually `video/mp4`)
- `properties`:
  - exactly one **primary input** (`is_primary_input=true`, type `video`)
  - text properties use placeholders (e.g., `{{PROMPT}}`) and are replaced server-side
  - image/video properties are handled by the worker asset pipeline

### Step 8: Create or update the fleet in Admin UI

Path: **Admin → ComfyUI → Fleets** (`/admin/comfyui/fleets`)

- Select a **Template** and **Instance Type** (single choice from the template allowlist).
- Scaling settings are derived from the template and **cannot** be edited in the UI.
- Same `fleet_slug` is allowed in both fleet stages; uniqueness is per `(fleet_stage, fleet_slug)`.

This writes the desired config to SSM:
`/bp/fleets/<fleet_stage>/<fleet_slug>/desired_config`

### Step 9: Provision the fleet ASG (GitHub Actions)

Run GitHub Action: **Provision GPU Fleet** (`.github/workflows/provision-gpu-fleet.yml`)

Inputs:
- `fleet_slug`
- `fleet_stage`
- `aws_region`

This reads `/bp/fleets/<fleet_stage>/<fleet_slug>/desired_config`, and deploys:
- `bp-gpu-fleet-<fleet_stage>-<fleet_slug>`

If you later change the template or instance type, run **Apply GPU Fleet Config**
(`.github/workflows/apply-gpu-fleet-config.yml`) to update the existing fleet stack.

### Step 10: Assign workflow to the fleet

Because the fleet is shared, the active bundle must contain assets for all assigned workflows.

Use either:
- **Admin → Workflows** → set the fleet assignment for the target fleet stage
- **Admin → ComfyUI → Fleets** → assign workflows

### Step 11: Activate the bundle for the fleet

Path: **Admin → ComfyUI → Fleets** → **Manage** → select bundle → **Activate**

Writes to:
- DB: `comfyui_gpu_fleets.active_bundle_id` / `active_bundle_s3_prefix`
- SSM: `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`

### Step 12: Bake and deploy the fleet AMI

Run GitHub Action: **Build Base GPU AMI** (`.github/workflows/build-ami.yml`)

Inputs:
- `fleet_slug`
- `fleet_stage`
- `aws_region` (optional)
- `start_instance_refresh` (optional)

This builds a **base AMI** (no assets baked), and writes:
`/bp/ami/fleets/<fleet_stage>/<fleet_slug> = ami-...`

Then run GitHub Action: **Bake GPU AMI (Active Bundle)** (`.github/workflows/bake-ami.yml`)

Inputs:
- `fleet_slug`
- `fleet_stage`
- `aws_region` (optional)
- `start_instance_refresh` (optional)

The bake workflow auto-resolves:
- `models_s3_bucket` from `/bp/models/bucket`
- `models_s3_prefix` from `/bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle`
- `bundle_id` from the prefix basename
- `packer_instance_profile` from `/bp/packer/instance_profile`

Optional: start an instance refresh to roll the ASG after updating the AMI.

### Step 13: Create the Effect

Path: **Admin → Effects** (`/admin/effects`)

Set:
- `workflow_id`
- `property_overrides` for non-user-configurable defaults
- pricing/flags (`credits_cost`, `is_active`, etc.)

The public effect exposes `configurable_properties` based on workflow properties.

## Verification checklist (UI + console)

### Assets and bundles
- **Admin → ComfyUI → Assets**: all needed files present, `kind` correct.
- **Admin → ComfyUI → Bundles**: open manifest and confirm:
  - `asset_s3_key` is `assets/<kind>/<sha256>`
  - `target_path` correct
  - `action` correct for archives

### SSM pointers

```bash
aws ssm get-parameter --name /bp/fleets/<fleet_stage>/<fleet_slug>/active_bundle --query Parameter.Value --output text
aws ssm get-parameter --name /bp/fleets/<fleet_stage>/<fleet_slug>/desired_config --query Parameter.Value --output text
aws ssm get-parameter --name /bp/ami/fleets/<fleet_stage>/<fleet_slug> --query Parameter.Value --output text
aws ssm get-parameter --name /bp/models/bucket --query Parameter.Value --output text
aws ssm get-parameter --name /bp/packer/instance_profile --query Parameter.Value --output text
```

### ASG + instance health

```bash
aws ec2 describe-launch-template-versions \
  --launch-template-name lt-<fleet_stage>-<fleet_slug> \
  --versions '$Latest'
```

On a running instance (SSM/SSH):

```bash
sudo tail -n 200 /var/log/comfyui-asset-sync.log
cat /opt/comfyui/.baked_bundle_id || true
cat /opt/comfyui/.active_bundle_id || true
sudo systemctl status comfyui.service --no-pager
sudo systemctl status comfyui-worker.service --no-pager
sudo cat /opt/worker/env
```

### Worker registration and routing
- **Admin → Workers**: worker appears as `registration_source=fleet`
- `last_seen_at` updates
- workflow assignments include the new workflow

### End-to-end processing
1. Use the public effect page
2. Upload input video
3. Submit with any configurable prompts

Expected:
- `queued → processing → completed`
- output video URL returned

### Output S3 verification

```bash
aws s3 ls s3://bp-media-<account>/tenants/<tenant_id>/ai-jobs/<job_id>/
```

### Placeholder verification

- **Text placeholders** are replaced server-side in `WorkflowPayloadService`.
- **Asset placeholders** are replaced in the worker before prompt execution.

Practical verification:
- Add a temporary overlay node in ComfyUI that renders the prompt text into output.

### Metrics and autoscaling

CloudWatch namespace: `ComfyUI/Workers`

Check metrics with dimensions:
`FleetSlug=<fleet_slug>, Stage=<fleet_stage>`

Ensure alarms exist:
- `<fleet_stage>-<fleet_slug>-queue-has-jobs`
- `<fleet_stage>-<fleet_slug>-queue-empty`

## Fleet-stage rollout on single system

Core infrastructure is deployed once (`bp-*` stacks). Promotion happens by fleet stage:

1. Keep test workflows and bundles on `fleet_stage=staging`.
2. Create/activate equivalent production fleet entries (`fleet_stage=production`).
3. Provision production fleet stack `bp-gpu-fleet-production-<fleet_slug>`.
4. Build/bake production AMI and refresh the production ASG.
5. Verify end-to-end jobs via the production fleet stage.

## Troubleshooting

- **Workers not registering**: check `/bp/fleets/<fleet_stage>/fleet-secret`, backend logs, and worker env.
- **Bundles not applying**: verify SSM active bundle path and `/var/log/comfyui-asset-sync.log`.
- **Missing outputs**: check worker logs and `/api/worker/fail` error messages.
- **Scale-to-zero stuck**: verify CloudWatch alarms and scale-to-zero Lambda logs.

