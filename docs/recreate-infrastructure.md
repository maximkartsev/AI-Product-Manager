# Recreate AWS infrastructure (new per-fleet GPU approach)

This repo now supports **only** the new GPU architecture:

- **Shared GPU stack**: `bp-<stage>-gpu-shared`
- **Per-fleet stacks**: `bp-<stage>-gpu-fleet-<fleet_slug>`

The legacy monolithic GPU stack (`bp-<stage>-gpu`) is **not** deployed by CDK anymore. If it still exists in CloudFormation, you must delete it manually.

## Safety notes (read first)

- **Deleting `bp-<stage>-compute` or `bp-<stage>-network` will take the app down** (ALB/ECS/VPC).
- **Stateful data is in `bp-<stage>-data`** (RDS, Redis, S3 buckets).
  - **staging**: buckets are destroyed and the DB is snapshotted on delete.
  - **production**: buckets + RDS are retained on delete; destroying the stack will **orphan** them and a later redeploy can fail due to name collisions unless you manually delete/import resources.

## Staging: full wipe + clean redeploy

Set:

```bash
STAGE=staging
AWS_REGION=us-east-1
```

### 1) Delete the legacy GPU stack (if it exists)

```bash
aws cloudformation describe-stacks --stack-name "bp-${STAGE}-gpu" --region "$AWS_REGION" >/dev/null 2>&1 \
  && aws cloudformation delete-stack --stack-name "bp-${STAGE}-gpu" --region "$AWS_REGION" \
  && aws cloudformation wait stack-delete-complete --stack-name "bp-${STAGE}-gpu" --region "$AWS_REGION" \
  || true
```

### 2) Delete any per-fleet GPU stacks

In CloudFormation, filter stacks by prefix `bp-${STAGE}-gpu-fleet-` and delete each one.

### 3) Destroy the remaining stacks

```bash
cd infrastructure

# Shared + core stacks
npx cdk destroy --context stage="$STAGE" \
  "bp-${STAGE}-gpu-shared" \
  "bp-${STAGE}-monitoring" \
  "bp-${STAGE}-compute" \
  "bp-${STAGE}-data" \
  "bp-${STAGE}-network" \
  "bp-${STAGE}-cicd"
```

### 4) Deploy the new baseline stacks

Use GitHub Actions **Deploy Infrastructure** (`deploy-infrastructure.yml`) with all core stacks enabled, or run:

```bash
cd infrastructure
npx cdk deploy --context stage="$STAGE" \
  "bp-${STAGE}-cicd" \
  "bp-${STAGE}-network" \
  "bp-${STAGE}-data" \
  "bp-${STAGE}-compute" \
  "bp-${STAGE}-monitoring" \
  "bp-${STAGE}-gpu-shared" \
  --require-approval never
```

### 5) Recreate fleets and provision GPU capacity

For each fleet you want:

1. Create the fleet in the Admin UI (template + instance type).
2. Run GitHub Actions **Provision GPU Fleet** (`provision-gpu-fleet.yml`) for that `fleet_slug`.
3. (Recommended) Run **Build GPU AMI** (`build-ami.yml`) for that `fleet_slug`, then refresh the ASG.

## Production

If you truly want a “blank slate” production environment, the safest approach is a **new AWS account** (clean CDK bootstrap + clean stack names).

If you destroy production stacks in-place, review `infrastructure/lib/stacks/data-stack.ts` removal policies first and plan for retained/orphaned resources (RDS + S3 buckets) and potential redeploy name collisions.

