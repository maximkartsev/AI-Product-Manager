# GPU Fleet Operations Runbook

Production-ready administrator documentation for creating ComfyUI workflows, uploading required assets, packaging them into bundles, deploying them to a shared GPU fleet ASG, and verifying end-to-end processing in staging and production.

## Overview

This system uses:
- **Content-addressed assets** in S3: `assets/<kind>/<sha256>`
- **Manifest-only bundles** in S3: `bundles/<bundle_id>/manifest.json`
- **Shared GPU fleet** (e.g., `gpu-default`) that can run many workflows
- **Active bundle pointers** in SSM: `/bp/<stage>/fleets/<fleet_slug>/active_bundle`
- **Desired fleet config** in SSM: `/bp/<stage>/fleets/<fleet_slug>/desired_config`

Workers boot from a fleet AMI, apply the active bundle, then self-register to the backend and pull jobs.

## Preconditions and environment setup

### AWS infrastructure

Ensure the following are deployed for the target stage:
- `bp-<stage>-data` (models/logs buckets + SSM pointers)
- `bp-<stage>-compute` (backend/frontend services)
- `bp-<stage>-gpu-shared` (scale-to-zero SNS + Lambda)
- `bp-<stage>-gpu-fleet-<fleet_slug>` (per-fleet ASG + user-data)

#### Check that the stacks exist

These are **CloudFormation stacks** created by CDK. Replace `<stage>` with `staging` or `production`.

CLI (example for staging):

```bash
STAGE=staging

# Sanity check: correct AWS account/region
aws sts get-caller-identity --query Account --output text
aws configure get region

# Check each required stack directly (prints StackStatus; errors if missing)
aws cloudformation describe-stacks --stack-name "bp-${STAGE}-data" --query "Stacks[0].StackStatus" --output text
aws cloudformation describe-stacks --stack-name "bp-${STAGE}-compute" --query "Stacks[0].StackStatus" --output text
aws cloudformation describe-stacks --stack-name "bp-${STAGE}-gpu-shared" --query "Stacks[0].StackStatus" --output text
```

Optional (requires `cloudformation:ListStacks` permission):

```bash
aws cloudformation list-stacks \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-${STAGE}-')].StackName" \
  --output table
```

If you get `AccessDenied` for CloudFormation APIs, you need additional IAM permissions (at least `cloudformation:DescribeStacks` to check, and broad CloudFormation + IAM permissions to deploy via CDK). If you don't control IAM, use the AWS Console or assume the deployment role used for CDK/CI.

AWS Console:
- Go to **CloudFormation → Stacks**
- Search for: `bp-<stage>-data`, `bp-<stage>-compute`, `bp-<stage>-gpu-shared`

#### If a stack is missing: deploy it (or redeploy)

Stacks have dependencies: `bp-<stage>-network` → `bp-<stage>-data` → `bp-<stage>-compute` → `bp-<stage>-gpu-shared` (monitoring is optional).

Deploy the chain (from repo root):

```bash
cd infrastructure
npm install

# One-time per AWS account/region:
npx cdk bootstrap

# Deploy required stacks (recommended order)
npx cdk deploy --context stage=${STAGE} \
  bp-${STAGE}-network \
  bp-${STAGE}-data \
  bp-${STAGE}-compute \
  bp-${STAGE}-gpu-shared

Per-fleet stacks (`bp-<stage>-gpu-fleet-<fleet_slug>`) are provisioned via GitHub Actions (see Step 9 below).
```

If you want *all* stacks (including monitoring/cicd), you can deploy everything:

```bash
cd infrastructure
npx cdk deploy --context stage=${STAGE} --all
```

#### Common blocker: CDK bootstrap/deploy permissions

`cdk bootstrap` and `cdk deploy` require **broad AWS permissions** (CloudFormation + IAM + ECR + S3 + SSM, etc.). If you see `AccessDenied` errors like:
- `ecr:CreateRepository`
- `iam:GetRole` / `iam:CreateRole` / `iam:AttachRolePolicy` / `iam:DeleteRole`
- `ssm:PutParameter`

…then your current AWS identity cannot bootstrap/deploy this account. Use an **admin/infra** role (preferred: AWS SSO/Identity Center admin permission set), or ask your platform team to bootstrap the account/region once.

If `cdk bootstrap` fails and leaves a `CDKToolkit` stack in `ROLLBACK_FAILED`, an admin must delete it in **CloudFormation** (and may need to manually delete leftover `cdk-hnb659fds-*` IAM roles/repositories before retrying).

#### Container images (ECR) must exist before deploying `bp-<stage>-compute`

The `bp-<stage>-compute` stack creates ECS services that reference **ECR images** by tag:

- Backend repo `bp-backend-<stage>`:
  - `nginx-latest`
  - `php-latest`
- Frontend repo `bp-frontend-<stage>`:
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
STAGE=staging

aws ecr describe-images \
  --repository-name "bp-backend-${STAGE}" \
  --query "imageDetails[].imageTags" \
  --output json

aws ecr describe-images \
  --repository-name "bp-frontend-${STAGE}" \
  --query "imageDetails[].imageTags" \
  --output json
```

If the repositories don’t exist at all, deploy the ECR stack first:

```bash
STAGE=staging
cd infrastructure
npx cdk deploy --context stage=${STAGE} "bp-${STAGE}-cicd"
```

##### Populate ECR via GitHub Actions (recommended)

This repo includes a manual GitHub Actions workflow: `.github/workflows/deploy.yml` (Actions → **Deploy**).

What it does (high level):
- **test-backend**: runs `php artisan test`
- **test-frontend**: runs `pnpm build`
- **build-and-push**: builds `linux/arm64` images and pushes the required tags to ECR
- **deploy-infrastructure** (manual gate via GitHub Environment): runs `npx cdk deploy --all --require-approval never`
- **deploy-services**: runs ECS deployments (`update-service --force-new-deployment`)

How to run it:
1. GitHub → **Actions** → **Deploy**
2. Click **Run workflow**
3. Wait for **build-and-push** to complete
4. Verify ECR tags exist (commands above)
5. Deploy `bp-<stage>-compute` (CDK) or let the workflow deploy services

##### `AWS_DEPLOY_ROLE_ARN` (GitHub Actions → AWS)

`AWS_DEPLOY_ROLE_ARN` is the IAM role that GitHub Actions assumes using OIDC (no long-lived AWS keys).

Where to configure it in GitHub:
- Repository → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**
  - Name: `AWS_DEPLOY_ROLE_ARN`
  - Value: `arn:aws:iam::<ACCOUNT_ID>:role/<ROLE_NAME>`

Important: jobs like **build-and-push** and **deploy-services** do **not** specify a GitHub Environment, so `AWS_DEPLOY_ROLE_ARN` must be a **repository secret** (not only an environment-scoped secret) unless you also update the workflow to attach an environment to those jobs.

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

After `bp-<stage>-compute` is deployed and images exist, use this checklist to confirm the app is actually usable.

##### 1) Confirm ECS services are stable

```bash
STAGE=staging

aws ecs wait services-stable \
  --cluster "bp-${STAGE}" \
  --services "bp-${STAGE}-backend" "bp-${STAGE}-frontend"
```

##### 2) Confirm the ALB routes are healthy (`/up` and `/`)

Get the base URL from the stack output:

```bash
STAGE=staging
API_BASE_URL=$(aws cloudformation describe-stacks \
  --stack-name "bp-${STAGE}-compute" \
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
STAGE=staging
aws logs tail "/ecs/bp-backend-${STAGE}" --follow
aws logs tail "/ecs/bp-frontend-${STAGE}" --follow
```

Common fatal misconfigurations you’ll see here:
- Missing/invalid `APP_KEY`
- DB connectivity issues
- Missing ECR tags or wrong image architecture (should be `linux/arm64`)

##### 4) Required one-time settings after first deploy

Even if stacks deploy, the app may not function until these are set:

- **Laravel `APP_KEY`** (Secrets Manager): `/bp/<stage>/laravel/app-key`
  - Must be a Laravel key string like `base64:...`
- **Fleet secret** (SSM): `/bp/<stage>/fleet-secret`
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
aws ssm get-parameter --name /bp/<stage>/models/bucket --query Parameter.Value --output text
aws ssm get-parameter --name /bp/<stage>/fleet-secret --query Parameter.Value --output text
```

If the fleet secret is still the placeholder:

```bash
aws ssm put-parameter \
  --name "/bp/<stage>/fleet-secret" \
  --value "$(openssl rand -hex 32)" \
  --type String \
  --overwrite
```

### Backend configuration

In AWS ECS, these are injected automatically (see `infrastructure/lib/stacks/compute-stack.ts`):
- `COMFYUI_MODELS_BUCKET`, `COMFYUI_MODELS_DISK=comfyui_models`
- `COMFYUI_LOGS_BUCKET`, `COMFYUI_LOGS_DISK=comfyui_logs`
- `COMFYUI_FLEET_SECRET` from `/bp/<stage>/fleet-secret`

For local admin use, follow `quickstart.md`:
- Media uploads may use MinIO via `AWS_*`
- ComfyUI models/logs should use **real AWS S3** via `COMFYUI_MODELS_*` / `COMFYUI_LOGS_*`

## Step-by-step execution (UI + console)

### Step 1: Launch the Dev GPU node

This gives you a live ComfyUI UI for iterating on workflows.

```bash
cd infrastructure/dev-gpu
./launch.sh
```

If you need to force a fleet AMI explicitly:

```bash
FLEET_SLUG=gpu-default
STAGE=staging
AMI_ID=$(aws ssm get-parameter --name "/bp/ami/fleets/${STAGE}/${FLEET_SLUG}" --query Parameter.Value --output text)
AMI_ID="$AMI_ID" ./launch.sh
```

Outcome: the script prints the ComfyUI URL `http://<public-ip>:8188`.

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
- `bundle_prefix`: `bundles/<bundle_id>`
- `models_bucket` (optional): `bp-models-<account>-<stage>` (derived from `/bp/<stage>/models/bucket` if omitted)

This runs SSM to execute:
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

This writes the desired config to SSM:
`/bp/<stage>/fleets/<fleet_slug>/desired_config`

### Step 9: Provision the fleet ASG (GitHub Actions)

Run GitHub Action: **Provision GPU Fleet** (`.github/workflows/provision-gpu-fleet.yml`)

Inputs:
- `stage`
- `fleet_slug`

This reads `/bp/<stage>/fleets/<fleet_slug>/desired_config` and deploys:
- `bp-<stage>-gpu-shared`
- `bp-<stage>-gpu-fleet-<fleet_slug>`

If you later change the template or instance type, run **Apply GPU Fleet Config**
(`.github/workflows/apply-gpu-fleet-config.yml`) to update the existing fleet stack.

### Step 10: Assign workflow to the fleet

Because the fleet is shared, the active bundle must contain assets for all assigned workflows.

Use either:
- **Admin → Workflows** → set **Staging Fleet**
- **Admin → ComfyUI → Fleets** → assign workflows

### Step 11: Activate the bundle for the staging fleet

Path: **Admin → ComfyUI → Fleets** → **Manage** → select bundle → **Activate**

Writes to:
- DB: `comfyui_gpu_fleets.active_bundle_id` / `active_bundle_s3_prefix`
- SSM: `/bp/<stage>/fleets/<fleet_slug>/active_bundle`

### Step 12: Bake and deploy the fleet AMI

Run GitHub Action: **Build GPU AMI** (`.github/workflows/build-ami.yml`)

Inputs:
- `fleet_slug`: `gpu-default`
- `stage`: `staging`
- `models_s3_bucket`: from `/bp/<stage>/models/bucket`
- `models_s3_prefix`: `bundles/<bundle_id>`
- `bundle_id`: `<bundle_id>`
- `start_instance_refresh` (optional): triggers ASG refresh after AMI update

This updates:
`/bp/ami/fleets/<stage>/<fleet_slug> = ami-...`

Roll the ASG to apply the new AMI:

```bash
aws autoscaling start-instance-refresh \
  --auto-scaling-group-name "asg-<stage>-<fleet_slug>" \
  --preferences '{"MinHealthyPercentage":90,"InstanceWarmup":300}'
```

### Step 11: Create the Effect

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
aws ssm get-parameter --name /bp/<stage>/fleets/<fleet_slug>/active_bundle --query Parameter.Value --output text
aws ssm get-parameter --name /bp/<stage>/fleets/<fleet_slug>/desired_config --query Parameter.Value --output text
aws ssm get-parameter --name /bp/ami/fleets/<stage>/<fleet_slug> --query Parameter.Value --output text
aws ssm get-parameter --name /bp/<stage>/models/bucket --query Parameter.Value --output text
```

### ASG + instance health

```bash
aws ec2 describe-launch-template-versions \
  --launch-template-name lt-<stage>-<fleet_slug> \
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

## Migration from monolithic GPU stack

If you already have `bp-<stage>-gpu` deployed (monolithic fleets), migrate to per-fleet stacks as follows:

1. Deploy the shared stack:
   - `bp-<stage>-gpu-shared`
2. Create/update the fleet in Admin UI (template + instance type) so `desired_config` is written.
3. Run **Provision GPU Fleet** for that fleet slug.
4. Verify workers register and jobs execute as expected.
5. When stable, delete the old `bp-<stage>-gpu` stack (after ensuring all fleets are migrated).

Note: the shared stack uses `bp-<stage>-scale-to-zero` SNS/Lambda. If the old stack already owns this topic, delete or migrate the old stack first to avoid name conflicts.

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
aws s3 ls s3://bp-media-<account>-<stage>/tenants/<tenant_id>/ai-jobs/<job_id>/
```

### Placeholder verification

- **Text placeholders** are replaced server-side in `WorkflowPayloadService`.
- **Asset placeholders** are replaced in the worker before prompt execution.

Practical verification:
- Add a temporary overlay node in ComfyUI that renders the prompt text into output.

### Metrics and autoscaling

CloudWatch namespace: `ComfyUI/Workers`

Check metrics with dimension:
`FleetSlug=<fleet_slug>`

Ensure alarms exist:
- `<stage>-<fleet_slug>-queue-has-jobs`
- `<stage>-<fleet_slug>-queue-empty`

## Production migration (separate AWS account)

### 1) Deploy production infrastructure

```bash
cd infrastructure
npx cdk deploy --context stage=production --all
```

Set required secrets in production:
- `/bp/production/laravel/app-key`
- `/bp/production/fleet-secret`

### 2) Copy bundles/assets to production models bucket

Copy required `bundles/<bundle_id>/manifest.json` and referenced `assets/<kind>/<sha256>` to the production models bucket.

### 3) Activate bundle and build AMI in production

Repeat Steps 9–10 using:
- `stage=production`
- production models bucket

### 4) Recreate workflows and effects in production

Repeat Steps 7 and 11 in the production admin UI.

### 5) Verify and enable traffic

Run a “golden path” job on a test tenant. Monitor CloudWatch alarms and logs. Then switch DNS to production.

## Troubleshooting

- **Workers not registering**: check `/bp/<stage>/fleet-secret`, backend logs, and worker env.
- **Bundles not applying**: verify SSM active bundle path and `/var/log/comfyui-asset-sync.log`.
- **Missing outputs**: check worker logs and `/api/worker/fail` error messages.
- **Scale-to-zero stuck**: verify CloudWatch alarms and scale-to-zero Lambda logs.

