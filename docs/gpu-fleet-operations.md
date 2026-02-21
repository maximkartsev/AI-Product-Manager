# GPU Fleet Operations Runbook

Production-ready administrator documentation for creating ComfyUI workflows, uploading required assets, packaging them into bundles, deploying them to a shared GPU fleet ASG, and verifying end-to-end processing in staging and production.

## Overview

This system uses:
- **Content-addressed assets** in S3: `assets/<kind>/<sha256>`
- **Manifest-only bundles** in S3: `bundles/<bundle_id>/manifest.json`
- **Shared GPU fleet** (e.g., `gpu-default`) that can run many workflows
- **Active bundle pointers** in SSM: `/bp/<stage>/fleets/<fleet_slug>/active_bundle`

Workers boot from a fleet AMI, apply the active bundle, then self-register to the backend and pull jobs.

## Preconditions and environment setup

### AWS infrastructure

Ensure the following are deployed for the target stage:
- `bp-<stage>-data` (models/logs buckets + SSM pointers)
- `bp-<stage>-compute` (backend/frontend services)
- `bp-<stage>-gpu` (fleet ASG + user-data)

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

The backend writes `bundles/<bundle_id>/manifest.json` to the models bucket.

### Step 5: Apply the bundle to the Dev GPU node

Recommended: GitHub Action `.github/workflows/apply-comfyui-bundle.yml`

Inputs:
- `instance_id`: dev GPU EC2 instance ID
- `fleet_slug`: e.g. `gpu-default`
- `bundle_prefix`: `bundles/<bundle_id>`
- `models_bucket`: `bp-models-<account>-<stage>`

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

### Step 8: Assign workflow to the shared fleet

Because the fleet is shared, the active bundle must contain assets for all assigned workflows.

Use either:
- **Admin → Workflows** → set **Staging Fleet**
- **Admin → ComfyUI → Fleets** → assign workflows

### Step 9: Activate the bundle for the staging fleet

Path: **Admin → ComfyUI → Fleets** → **Manage** → select bundle → **Activate**

Writes to:
- DB: `comfyui_gpu_fleets.active_bundle_id` / `active_bundle_s3_prefix`
- SSM: `/bp/<stage>/fleets/<fleet_slug>/active_bundle`

### Step 10: Bake and deploy the fleet AMI

Run GitHub Action: **Build GPU AMI** (`.github/workflows/build-ami.yml`)

Inputs:
- `fleet_slug`: `gpu-default`
- `stage`: `staging`
- `models_s3_bucket`: from `/bp/<stage>/models/bucket`
- `models_s3_prefix`: `bundles/<bundle_id>`
- `bundle_id`: `<bundle_id>`

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

