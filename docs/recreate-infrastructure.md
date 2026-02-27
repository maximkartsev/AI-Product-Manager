# Recreate AWS infrastructure (single-system + fleet stages)

This project now uses:

- One shared core system: `bp-cicd`, `bp-network`, `bp-data`, `bp-compute`, `bp-monitoring`, `bp-gpu-shared`
- Per-fleet-stage GPU stacks: `bp-gpu-fleet-<fleet_stage>-<fleet_slug>`

If you want a **true blank slate (delete everything, including retained data and CDK bootstrap)**, use:
- `docs/aws-full-reset-single-system.md`

This document is the shorter operator flow for destroying and rebuilding the stack set.

## Safety notes

- Destroying `bp-compute` and `bp-network` takes the app offline.
- `bp-data` contains stateful resources (RDS/S3/Secrets/SSM). Deleting stacks alone does not always remove everything.
- Fleet stage is now explicit (`staging` or `production`) and applies only to GPU fleets and fleet-scoped SSM paths.

## 0) Prerequisites

```bash
aws sts get-caller-identity
aws configure get region
```

From repository root:

```bash
cd infrastructure
npm ci
```

## 1) Destroy per-fleet stacks first

List fleet stacks:

```bash
aws cloudformation list-stacks \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-gpu-fleet-')].StackName" \
  --output text
```

Destroy each stack (repeat for every returned name):

```bash
npx cdk destroy bp-gpu-fleet-<fleet_stage>-<fleet_slug>
```

## 2) Destroy shared/core stacks

Use reverse dependency order:

```bash
npx cdk destroy \
  bp-gpu-shared \
  bp-monitoring \
  bp-compute \
  bp-data \
  bp-network \
  bp-cicd
```

## 3) Remove legacy stacks if they still exist

Older deployments may still have stage-prefixed stacks. Delete them if found:

```bash
aws cloudformation list-stacks \
  --stack-status-filter CREATE_COMPLETE UPDATE_COMPLETE UPDATE_ROLLBACK_COMPLETE \
  --query "StackSummaries[?starts_with(StackName, 'bp-staging-') || starts_with(StackName, 'bp-production-') || StackName=='bp-staging-gpu' || StackName=='bp-production-gpu'].StackName" \
  --output table
```

Delete each legacy stack:

```bash
aws cloudformation delete-stack --stack-name <legacy_stack_name>
aws cloudformation wait stack-delete-complete --stack-name <legacy_stack_name>
```

## 4) Re-bootstrap CDK (if needed)

If you intentionally removed bootstrap resources, run:

```bash
npx cdk bootstrap
```

## 5) Re-deploy baseline stacks

```bash
npx cdk deploy \
  bp-cicd \
  bp-network \
  bp-data \
  bp-compute \
  bp-monitoring \
  bp-gpu-shared \
  --require-approval never
```

## 6) Re-seed required secrets and env mappings

Use the sync script (single env file, two fleet secrets):

```powershell
powershell -NoProfile -File .\scripts\sync-env-to-aws.ps1 `
  -Region us-east-1 `
  -EnvPath ..\backend\.env `
  -AppleP8Path C:\secure\keys\apple-production.p8
```

This writes:
- `/bp/laravel/app-key`
- `/bp/oauth/secrets`
- `/bp/fleets/staging/fleet-secret`
- `/bp/fleets/production/fleet-secret`

## 7) Rebuild runtime and data

1. Run GitHub Actions **Deploy** (`.github/workflows/deploy.yml`) to build/push images and redeploy ECS services.
2. Run **DB Migrate** (`.github/workflows/db-migrate.yml`).
3. (Optional) Run **DB Seed** (`.github/workflows/db-seed.yml`) for non-production data.

## 8) Recreate fleets

For each required fleet:

1. Create fleet in Admin UI (includes `fleet_stage` + `fleet_slug`, same slug allowed across stages).
2. Run **Provision GPU Fleet** (`.github/workflows/provision-gpu-fleet.yml`) with explicit `fleet_stage`.
3. Build/bake AMI via:
   - `.github/workflows/build-ami.yml`
   - `.github/workflows/bake-ami.yml`
4. Refresh ASG if needed.

## 9) Post-recreate checks

```bash
aws cloudformation describe-stacks --stack-name bp-compute --query "Stacks[0].StackStatus" --output text
aws ecs describe-services --cluster bp --services bp-backend bp-frontend --query "services[].{name:serviceName,running:runningCount,desired:desiredCount}"
aws logs tail /ecs/bp-backend --since 10m
```

Also verify:
- `/bp` parameters/secrets exist and are non-placeholder
- `/api/up` returns HTTP 200
- A worker can register in both fleet stages (`staging` and `production`)

