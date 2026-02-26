# Remove backward compatibility (fresh deployments only)

## Goal

This repo should assume **fresh deployments only**, with **no backward-compatibility paths** for:
- Fleet secrets
- CloudWatch metrics dimensions

## Decisions

### 1) Fleet secrets: stage-specific only

- **Remove** legacy fallback to `services.comfyui.fleet_secret`.
- **Require**:
  - `services.comfyui.fleet_secret_staging`
  - `services.comfyui.fleet_secret_production`
- Fleet registration rejects requests when the stage-specific secret is missing.

### 2) CloudWatch metrics: Stage dimension required

- **Remove** publishing of legacy metrics without a `Stage` dimension.
- Continue publishing metrics for **both** stages each run (staging + production), but always with:
  - `Stage=<stage>`
  - plus `FleetSlug=<fleet_slug>` or `WorkflowSlug=<workflow_slug>`

### 3) CDK + alarms: Stage dimension required

- All alarms/queries for `ComfyUI/Workers` metrics must include `Stage` dimension.

## Files expected to change

- `backend/app/Http/Middleware/EnsureFleetSecret.php`
- `backend/config/services.php`
- `backend/routes/console.php`
- `backend/tests/Feature/FleetRegistrationTest.php`
- Docs referencing stage-specific fleet secrets or `FleetSlug`-only metrics:
  - `infrastructure/README.md`
  - `docs/gpu-fleet-operations.md`
  - `docs/ai-supported/adr/0005-aws-autoscaling-comfyui-workers.md`

## Verification

- `php artisan test` (once DB connectivity is available in the docker environment).

