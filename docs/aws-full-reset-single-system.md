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

```bash
LEGACY_STACKS=$(aws cloudformation list-stacks \
  --region "$AWS_REGION" \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-staging-') || starts_with(StackName, 'bp-production-')].StackName" \
  --output text)

for STACK in $LEGACY_STACKS; do
  aws cloudformation delete-stack --region "$AWS_REGION" --stack-name "$STACK"
done

for STACK in $LEGACY_STACKS; do
  aws cloudformation wait stack-delete-complete --region "$AWS_REGION" --stack-name "$STACK"
done
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

```bash
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)

for B in "bp-media-${ACCOUNT_ID}" "bp-models-${ACCOUNT_ID}" "bp-access-logs-${ACCOUNT_ID}" "bp-logs-${ACCOUNT_ID}"; do
  aws s3 rb "s3://${B}" --force --region "$AWS_REGION" || true
done
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
  aws s3 rb "s3://${B}" --force --region "$AWS_REGION" || true
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

## 7) Fresh install from scratch

From repository root:

```bash
cd infrastructure
npm ci
npx cdk bootstrap
```

Deploy stacks in order:

```bash
npx cdk deploy bp-cicd bp-network bp-data bp-compute bp-monitoring bp-gpu-shared --require-approval never
```

## 8) Re-seed required app secrets and env values

Use the script from `infrastructure/scripts/sync-env-to-aws.ps1`:

```powershell
powershell -NoProfile -File .\scripts\sync-env-to-aws.ps1 `
  -Region us-east-1 `
  -EnvPath ..\backend\.env `
  -AppleP8Path C:\secure\keys\Apple_AuthKey_XXXXXX.p8
```

This writes:

- `/bp/laravel/app-key`
- `/bp/oauth/secrets`
- `/bp/fleets/staging/fleet-secret`
- `/bp/fleets/production/fleet-secret`

## 9) Rebuild app runtime and database

1. Run GitHub Actions **Deploy** (`.github/workflows/deploy.yml`) to build/push images and restart ECS services.
2. Run **DB Migrate** (`.github/workflows/db-migrate.yml`).
3. Optional for non-production: run **DB Seed** (`.github/workflows/db-seed.yml`).

## 10) Recreate GPU fleets

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
