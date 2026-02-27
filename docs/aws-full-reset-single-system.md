# AWS Full Reset and Reinstall (Single-System)

This runbook is the **destructive** "nuke and pave" path for the current architecture:

- One shared system (no global `staging`/`production` stacks)
- Fleet stage exists only for GPU fleets (`staging`, `production`)

Use this when you want a **true blank slate** in one AWS account/region:

- Delete app stacks
- Delete retained data resources
- Delete `/bp/*` SSM + Secrets
- Delete AMIs/snapshots and dev GPU instances
- Delete CDK bootstrap (`CDKToolkit`, `cdk-hnb659fds-*`)
- Reinstall everything from scratch

## 0) Hard warnings

- This procedure **deletes data permanently** (RDS/S3/etc.) unless you take backups.
- Run it only in the intended AWS account and region.
- If this account hosts other workloads, review every command and narrow filters first.

## 1) Preconditions

```bash
aws sts get-caller-identity
aws configure get region
```

Set your target region explicitly:

```bash
export AWS_REGION=us-east-1
```

Optional explicit profile:

```bash
export AWS_PROFILE=default
```

## 2) Optional backup (before destruction)

### RDS final snapshot (recommended)

List DB instances:

```bash
aws rds describe-db-instances \
  --region "$AWS_REGION" \
  --query "DBInstances[].DBInstanceIdentifier" \
  --output text
```

Create a snapshot for the target DB:

```bash
DB_ID=<db_instance_identifier>
SNAPSHOT_ID="prewipe-${DB_ID}-$(date +%Y%m%d%H%M%S)"
aws rds create-db-snapshot \
  --region "$AWS_REGION" \
  --db-instance-identifier "$DB_ID" \
  --db-snapshot-identifier "$SNAPSHOT_ID"
```

### S3 backup (optional)

```bash
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
aws s3 sync "s3://bp-media-${ACCOUNT_ID}" "./backup-media-${ACCOUNT_ID}" --region "$AWS_REGION"
aws s3 sync "s3://bp-models-${ACCOUNT_ID}" "./backup-models-${ACCOUNT_ID}" --region "$AWS_REGION"
aws s3 sync "s3://bp-logs-${ACCOUNT_ID}" "./backup-logs-${ACCOUNT_ID}" --region "$AWS_REGION"
```

## 3) Stop workloads (reduce delete failures)

### Scale ECS services to zero

```bash
aws ecs update-service \
  --region "$AWS_REGION" \
  --cluster bp \
  --service bp-backend \
  --desired-count 0 || true

aws ecs update-service \
  --region "$AWS_REGION" \
  --cluster bp \
  --service bp-frontend \
  --desired-count 0 || true
```

### Scale all GPU ASGs to zero

```bash
for ASG in $(aws autoscaling describe-auto-scaling-groups \
  --region "$AWS_REGION" \
  --query "AutoScalingGroups[?starts_with(AutoScalingGroupName, 'asg-')].AutoScalingGroupName" \
  --output text); do
  aws autoscaling update-auto-scaling-group \
    --region "$AWS_REGION" \
    --auto-scaling-group-name "$ASG" \
    --min-size 0 --max-size 0 --desired-capacity 0
done
```

### Terminate dev GPU instances created by Actions

```bash
DEV_IDS=$(aws ec2 describe-instances \
  --region "$AWS_REGION" \
  --filters "Name=tag:ManagedBy,Values=dev-gpu-action" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query "Reservations[].Instances[].InstanceId" \
  --output text)

if [ -n "${DEV_IDS:-}" ]; then
  aws ec2 terminate-instances --region "$AWS_REGION" --instance-ids $DEV_IDS
  aws ec2 wait instance-terminated --region "$AWS_REGION" --instance-ids $DEV_IDS
fi
```

## 4) Delete CloudFormation stacks

Delete order matters.

### 4.1 Delete per-fleet stacks first

```bash
FLEET_STACKS=$(aws cloudformation list-stacks \
  --region "$AWS_REGION" \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-gpu-fleet-')].StackName" \
  --output text)

for STACK in $FLEET_STACKS; do
  aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "$STACK"
done

for STACK in $FLEET_STACKS; do
  aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "$STACK"
done
```

### 4.2 Delete shared/core stacks

```bash
for STACK in bp-gpu-shared bp-monitoring bp-compute bp-data bp-network bp-cicd; do
  aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "$STACK" || true
done

for STACK in bp-gpu-shared bp-monitoring bp-compute bp-data bp-network bp-cicd; do
  aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "$STACK" || true
done
```

### 4.3 Delete legacy stage-prefixed stacks if present

Older deployments used stage-prefixed stacks (for example `bp-staging-compute`). **Delete these in dependency order**. Do not delete them all at once.

First, discover which legacy stacks exist:

```bash
aws cloudformation list-stacks \
  --region "$AWS_REGION" \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-staging-') || starts_with(StackName, 'bp-production-')].StackName" \
  --output table
```

Then delete **per stage**, in this order (runs for both `staging` and `production`):

```bash
for STAGE in staging production; do

# 1) Per-fleet GPU stacks (legacy) if present
FLEET_STACKS=$(aws cloudformation list-stacks \
  --region "$AWS_REGION" \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-${STAGE}-gpu-fleet-')].StackName" \
  --output text)
for STACK in $FLEET_STACKS; do
  aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "$STACK"
done
for STACK in $FLEET_STACKS; do
  aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "$STACK"
done

# 2) Shared GPU stack (legacy)
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "bp-${STAGE}-gpu-shared" || true
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "bp-${STAGE}-gpu-shared" || true

# 3) Monitoring (legacy)
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "bp-${STAGE}-monitoring" || true
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "bp-${STAGE}-monitoring" || true

# 4) Compute (legacy) — MUST be deleted before data/network
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "bp-${STAGE}-compute" || true
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "bp-${STAGE}-compute" || true

# 5) Data (legacy)
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "bp-${STAGE}-data" || true
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "bp-${STAGE}-data" || true

# 6) Network (legacy)
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "bp-${STAGE}-network" || true
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "bp-${STAGE}-network" || true

# 7) CI/CD (legacy)
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "bp-${STAGE}-cicd" || true
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "bp-${STAGE}-cicd" || true

done
```

#### Legacy compute can fail to delete (ECS capacity providers “in use”)

If `bp-<stage>-compute` fails with `DELETE_FAILED` due to `AWS::ECS::ClusterCapacityProviderAssociations` (capacity provider is in use), clean up the legacy ECS cluster and retry:

```bash
STAGE=staging
CLUSTER="bp-${STAGE}"

# Delete any remaining services in the cluster (if any)
SERVICES=$(aws ecs list-services --region "$AWS_REGION" --cluster "$CLUSTER" --query "serviceArns[]" --output text)
for SVC in $SERVICES; do
  aws ecs update-service --region "$AWS_REGION" --cluster "$CLUSTER" --service "$SVC" --desired-count 0 || true
  aws ecs delete-service --region "$AWS_REGION" --cluster "$CLUSTER" --service "$SVC" --force || true
done

# Stop any remaining tasks (if any) — often one-off migration tasks
TASKS=$(aws ecs list-tasks --region "$AWS_REGION" --cluster "$CLUSTER" --desired-status RUNNING --query "taskArns[]" --output text)
for TASK in $TASKS; do
  aws ecs stop-task --region "$AWS_REGION" --cluster "$CLUSTER" --task "$TASK" || true
done

# Finally, delete the cluster (it should become INACTIVE)
aws ecs delete-cluster --region "$AWS_REGION" --cluster "$CLUSTER" || true

# Retry stack deletion after cluster cleanup
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "bp-${STAGE}-compute"
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "bp-${STAGE}-compute"
```

## 5) Delete retained/orphaned resources (required for true blank slate)

### 5.1 RDS cleanup (deletion protection + instance + snapshots)

List DBs:

```bash
aws rds describe-db-instances \
  --region "$AWS_REGION" \
  --query "DBInstances[].DBInstanceIdentifier" \
  --output table
```

Delete target DB instance(s):

```bash
DB_ID=<db_instance_identifier>

aws rds modify-db-instance \
  --region "$AWS_REGION" \
  --db-instance-identifier "$DB_ID" \
  --no-deletion-protection \
  --apply-immediately

aws rds delete-db-instance \
  --region "$AWS_REGION" \
  --db-instance-identifier "$DB_ID" \
  --skip-final-snapshot
```

Optional snapshot cleanup:

```bash
for SNAP in $(aws rds describe-db-snapshots \
  --region "$AWS_REGION" \
  --snapshot-type manual \
  --query "DBSnapshots[].DBSnapshotIdentifier" \
  --output text); do
  aws rds delete-db-snapshot --region "$AWS_REGION" --db-snapshot-identifier "$SNAP"
done
```

### 5.2 S3 cleanup (retained buckets)

Some older deployments used stage-suffixed buckets (for example `bp-media-<account>-staging`). Delete whatever exists for this account.

```bash
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)

for B in $(aws s3api list-buckets --query 'Buckets[].Name' --output text); do
  case "$B" in
    bp-media-${ACCOUNT_ID}*|bp-models-${ACCOUNT_ID}*|bp-logs-${ACCOUNT_ID}*|bp-access-logs-${ACCOUNT_ID}*)
      aws s3 rb "s3://${B}" --force --region "$AWS_REGION" || true
      ;;
  esac
done
```

If you also have non-`bp-*` legacy buckets you want removed (example: `dzzzs-comfyui-assets` / `dzzzs-comfyui-logs`), delete them explicitly:

```bash
aws s3 rb "s3://dzzzs-comfyui-assets" --force --region "$AWS_REGION" || true
aws s3 rb "s3://dzzzs-comfyui-logs" --force --region "$AWS_REGION" || true
```

### 5.3 Secrets Manager cleanup (`/bp/*`)

```bash
for SECRET in $(aws secretsmanager list-secrets \
  --region "$AWS_REGION" \
  --query "SecretList[?starts_with(Name, '/bp/')].Name" \
  --output text); do
  aws secretsmanager delete-secret \
    --region "$AWS_REGION" \
    --secret-id "$SECRET" \
    --force-delete-without-recovery
done
```

### 5.4 SSM Parameter Store cleanup (`/bp`)

```bash
for PARAM in $(aws ssm get-parameters-by-path \
  --region "$AWS_REGION" \
  --path "/bp" \
  --recursive \
  --query "Parameters[].Name" \
  --output text); do
  aws ssm delete-parameter --region "$AWS_REGION" --name "$PARAM"
done
```

### 5.5 CloudWatch log groups cleanup

```bash
for LG in $(aws logs describe-log-groups \
  --region "$AWS_REGION" \
  --query "logGroups[?starts_with(logGroupName, '/ecs/bp-') || starts_with(logGroupName, '/gpu-workers/')].logGroupName" \
  --output text); do
  aws logs delete-log-group --region "$AWS_REGION" --log-group-name "$LG"
done
```

### 5.6 AMI and snapshot cleanup (packer-built images)

```bash
AMI_IDS=$(aws ec2 describe-images \
  --region "$AWS_REGION" \
  --owners self \
  --filters "Name=tag:ManagedBy,Values=packer" \
  --query "Images[].ImageId" \
  --output text)

for AMI in $AMI_IDS; do
  SNAP_IDS=$(aws ec2 describe-images \
    --region "$AWS_REGION" \
    --image-ids "$AMI" \
    --query "Images[0].BlockDeviceMappings[].Ebs.SnapshotId" \
    --output text)

  aws ec2 deregister-image --region "$AWS_REGION" --image-id "$AMI"

  for SNAP in $SNAP_IDS; do
    aws ec2 delete-snapshot --region "$AWS_REGION" --snapshot-id "$SNAP"
  done
done
```

### 5.7 Optional: clean dev action security groups

```bash
for SG in $(aws ec2 describe-security-groups \
  --region "$AWS_REGION" \
  --filters "Name=tag:ManagedBy,Values=dev-gpu-action" \
  --query "SecurityGroups[].GroupId" \
  --output text); do
  aws ec2 delete-security-group --region "$AWS_REGION" --group-id "$SG" || true
done
```

## 6) Remove CDK bootstrap (hard reset)

### 6.1 Remove bootstrap assets bucket/repo leftovers

```bash
for B in $(aws s3api list-buckets \
  --query "Buckets[?starts_with(Name, 'cdk-hnb659fds-assets-')].Name" \
  --output text); do
  # CDK bootstrap assets bucket is usually versioned; plain `rb --force` may fail with BucketNotEmpty.
  aws s3 rb "s3://${B}" --force --region "$AWS_REGION" || true

  # If the bucket is still not empty, delete *all versions and delete markers* then delete the bucket.
  BUCKET="$B" python3 - <<'PY'
import json, os, subprocess

bucket = os.environ.get("BUCKET")
if not bucket:
    raise SystemExit("BUCKET env var is required")

def aws(*args):
    return subprocess.check_output(["aws", *args], text=True)

key_marker = None
ver_marker = None

while True:
    cmd = ["s3api", "list-object-versions", "--bucket", bucket, "--output", "json"]
    if key_marker is not None and ver_marker is not None:
        cmd += ["--key-marker", key_marker, "--version-id-marker", ver_marker]

    data = json.loads(aws(*cmd))

    objs = []
    for v in data.get("Versions", []):
        objs.append({"Key": v["Key"], "VersionId": v["VersionId"]})
    for m in data.get("DeleteMarkers", []):
        objs.append({"Key": m["Key"], "VersionId": m["VersionId"]})

    if objs:
        payload = json.dumps({"Objects": objs, "Quiet": True})
        subprocess.check_call(["aws", "s3api", "delete-objects", "--bucket", bucket, "--delete", payload])

    if not data.get("IsTruncated"):
        break

    key_marker = data["NextKeyMarker"]
    ver_marker = data["NextVersionIdMarker"]
PY

  aws s3 rb "s3://${B}" --region "$AWS_REGION" || true
done

for R in $(aws ecr describe-repositories \
  --region "$AWS_REGION" \
  --query "repositories[?starts_with(repositoryName, 'cdk-hnb659fds-container-assets-')].repositoryName" \
  --output text); do
  aws ecr delete-repository --region "$AWS_REGION" --repository-name "$R" --force || true
done
```

### 6.2 Delete `CDKToolkit`

```bash
aws cloudformation delete-stack --region "$AWS_REGION" --stack-name CDKToolkit || true
aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name CDKToolkit || true
```

### 6.3 Remove bootstrap SSM version param (if still present)

```bash
aws ssm delete-parameter \
  --region "$AWS_REGION" \
  --name /cdk-bootstrap/hnb659fds/version || true
```

## 7) Fresh install from scratch (CLI, correct order)

This section is written to avoid common first-deploy failures:

- `bp-compute` needs **ECR repos + image tags** to exist.
- The backend needs valid **`APP_KEY`** and OAuth payloads to boot cleanly (otherwise ECS health checks fail and the compute stack can roll back).
- If you want HTTPS on first deploy, you must have an **ACM certificate ARN** ready before deploying `bp-compute`.

### 7.1 Bootstrap CDK (once per account/region)

From repository root:

```bash
cd infrastructure
npm ci
npx cdk bootstrap
```

### 7.2 Deploy CI/CD first (creates ECR repos)

```bash
npx cdk deploy bp-cicd --require-approval never
```

### 7.3 Build and push container images (must exist before `bp-compute`)

You must push these tags:

- `bp-backend:nginx-latest`
- `bp-backend:php-latest`
- `bp-frontend:latest`

Example (from repo root; requires Docker buildx set up):

```bash
AWS_REGION="${AWS_REGION:-us-east-1}"
AWS_ACCOUNT_ID="$(aws sts get-caller-identity --query Account --output text)"

aws ecr get-login-password --region "$AWS_REGION" | \
  docker login --username AWS --password-stdin "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"

# Build ARM64 images
docker buildx build --platform linux/arm64 -f infrastructure/docker/backend/Dockerfile.nginx -t bp-backend:nginx-latest .
docker buildx build --platform linux/arm64 -f infrastructure/docker/backend/Dockerfile.php-fpm -t bp-backend:php-latest .
docker buildx build --platform linux/arm64 -f frontend/Dockerfile -t bp-frontend:latest ./frontend

# Tag for ECR
docker tag bp-backend:nginx-latest "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend:nginx-latest"
docker tag bp-backend:php-latest "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend:php-latest"
docker tag bp-frontend:latest "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-frontend:latest"

# Push
docker push "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend:nginx-latest"
docker push "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-backend:php-latest"
docker push "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/bp-frontend:latest"
```

### 7.4 Deploy network + data

```bash
npx cdk deploy bp-network bp-data --require-approval never
```

## 8) Seed required secrets (before deploying `bp-compute`)

After `bp-data` is deployed, the secret containers exist:

- `/bp/laravel/app-key`
- `/bp/oauth/secrets`
- `/bp/fleets/staging/fleet-secret`
- `/bp/fleets/production/fleet-secret`

### 8.1 Laravel APP_KEY (Secrets Manager)

Generate a Laravel key locally and store it.

Generate the key (prints a `base64:...` value):

```bash
echo "base64:$(openssl rand -base64 32)"
```

OR

```bash
cd backend
composer install
php artisan key:generate --show
```

Then store it in Secrets Manager:

```bash
APP_KEY_VALUE="base64:PASTE_LARAVEL_KEY_HERE"
aws secretsmanager put-secret-value --region "$AWS_REGION" --secret-id "/bp/laravel/app-key" --secret-string "$APP_KEY_VALUE"
```

### 8.2 Fleet secrets (SSM)

```bash
aws ssm put-parameter --region "$AWS_REGION" --name "/bp/fleets/staging/fleet-secret" --type String --value "$(openssl rand -hex 48)" --overwrite
aws ssm put-parameter --region "$AWS_REGION" --name "/bp/fleets/production/fleet-secret" --type String --value "$(openssl rand -hex 48)" --overwrite
```

### 8.3 OAuth secrets (Secrets Manager, optional but recommended for remote login)

Set `/bp/oauth/secrets` as **one JSON payload**.

Apple requires a private key file (`.p8`). Store its **raw file bytes** as base64 in the JSON field `apple_private_key_p8_b64`. At runtime, ECS decodes it into a file inside the container.

Build the base64 string from the `.p8` file:

```bash
APPLE_P8_PATH="/path/to/AuthKey_XXXXXX.p8"

# GNU coreutils (recommended)
APPLE_P8_B64="$(base64 -w 0 "$APPLE_P8_PATH")"

# If your base64 doesn't support -w:
# APPLE_P8_B64="$(base64 "$APPLE_P8_PATH" | tr -d '\n')"
```

Sanity-check that the variable is not empty and decodes back to the same file bytes:

```bash
echo "len=$(echo -n "$APPLE_P8_B64" | wc -c)"

echo "$APPLE_P8_B64" | base64 -d > /tmp/apple.p8
sha256sum "$APPLE_P8_PATH" /tmp/apple.p8
head -n 2 /tmp/apple.p8
```

Notes:
- If `/tmp/apple.p8` is empty, `APPLE_P8_B64` was not set in your current shell session.
- The decoded file should start with `-----BEGIN PRIVATE KEY-----`.

Then write the full JSON secret:

```bash
aws secretsmanager put-secret-value --region "$AWS_REGION" \
  --secret-id "/bp/oauth/secrets" \
  --secret-string "{
    \"google_client_id\":\"...\",
    \"google_client_secret\":\"...\",
    \"tiktok_client_id\":\"...\",
    \"tiktok_client_secret\":\"...\",
    \"apple_client_id\":\"...\",
    \"apple_client_secret\":\"\",
    \"apple_key_id\":\"...\",
    \"apple_team_id\":\"...\",
    \"apple_private_key_p8_b64\":\"${APPLE_P8_B64}\"
  }"
```

### 8.4 Optional: keep `.env` as the source-of-truth (PowerShell sync script)

`infrastructure/scripts/sync-env-to-aws.ps1` is best used **after `bp-compute` exists** because it forces an ECS backend redeploy (`bp-backend`).

## 9) Deploy compute + monitoring + gpu-shared

### 9.1 Optional: enable HTTPS on first deploy (domain + cert)

If you have a domain + ACM certificate ARN ready, pass both contexts when deploying `bp-compute`:

```bash
npx cdk deploy bp-compute \
  --context domainName=app.example.com \
  --context certificateArn=arn:aws:acm:us-east-1:123456789012:certificate/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx \
  --require-approval never
```

If you don’t provide a certificate, `bp-compute` will deploy HTTP-only and output the ALB DNS name.

### 9.2 Deploy compute (HTTP-only) and the remaining core stacks

```bash
npx cdk deploy bp-compute bp-monitoring bp-gpu-shared --require-approval never
```

### 9.3 After compute exists: run the sync script (recommended)

From `infrastructure/` on Windows:

```powershell
powershell -NoProfile -File .\scripts\sync-env-to-aws.ps1 `
  -Region us-east-1 `
  -EnvPath ..\backend\.env `
  -AppleP8Path C:\secure\keys\Apple_AuthKey_XXXXXX.p8
```

## 10) Run database migrations (CLI)

This uses a one-off Fargate task based on `bp-backend` and requires subnet + SG values.

Resolve them from the running backend ECS service:

```bash
aws ecs describe-services \
  --region "$AWS_REGION" \
  --cluster bp \
  --services bp-backend \
  --query "services[0].networkConfiguration.awsvpcConfiguration" \
  --output json
```

Then run:

```bash
aws ecs run-task \
  --region "$AWS_REGION" \
  --cluster bp \
  --task-definition bp-backend \
  --launch-type FARGATE \
  --network-configuration '{"awsvpcConfiguration":{"subnets":["subnet-...","subnet-..."],"securityGroups":["sg-..."]}}' \
  --overrides '{"containerOverrides":[{"name":"php-fpm","command":["php","artisan","migrate","--force"]}]}' 

aws ecs run-task \
  --region "$AWS_REGION" \
  --cluster bp \
  --task-definition bp-backend \
  --launch-type FARGATE \
  --network-configuration '{"awsvpcConfiguration":{"subnets":["subnet-...","subnet-..."],"securityGroups":["sg-..."]}}' \
  --overrides '{"containerOverrides":[{"name":"php-fpm","command":["php","artisan","tenancy:pools-migrate","--force"]}]}' 
```

## 11) Recreate GPU fleets

For each fleet needed:

1. Create fleet in Admin UI with `fleet_stage` + `fleet_slug`.
2. Run **Provision GPU Fleet** (`.github/workflows/provision-gpu-fleet.yml`) with both `fleet_slug` and `fleet_stage`.
3. Build/bake AMI:
   - `.github/workflows/build-ami.yml`
   - `.github/workflows/bake-ami.yml`
4. Refresh ASG if needed.

## 11) Final verification checklist

### Wipe verification

Before reinstall, verify none remain:

```bash
aws cloudformation list-stacks \
  --region "$AWS_REGION" \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp') || starts_with(StackName, 'bp-staging-') || starts_with(StackName, 'bp-production-')].StackName" \
  --output table

aws ssm get-parameters-by-path --region "$AWS_REGION" --path /bp --recursive --query "Parameters[].Name" --output text
aws secretsmanager list-secrets --region "$AWS_REGION" --query "SecretList[?starts_with(Name, '/bp/')].Name" --output text
```

### Reinstall verification

After reinstall:

```bash
aws cloudformation describe-stacks --region "$AWS_REGION" --stack-name bp-compute --query "Stacks[0].StackStatus" --output text
aws ecs describe-services --region "$AWS_REGION" --cluster bp --services bp-backend bp-frontend --query "services[].{name:serviceName,running:runningCount,desired:desiredCount}" --output table
```

Then confirm:

- `/api/up` returns `200`
- backend/frontend logs are clean (`/ecs/bp-backend`, `/ecs/bp-frontend`)
- worker registration works for both fleet stages (`staging` and `production`)
- one test job runs end-to-end
