# ADR-0005: AWS Auto Scaling for ComfyUI Workers

**Status:** Accepted (updated 2026-02-15)
**Date:** 2026-02-16

## Context

ComfyUI GPU workers process image-to-video and video-to-video jobs. Currently workers are manually provisioned. We need automatic scaling: scale to zero when idle, scale up under load, with shared GPU fleets that can serve multiple workflows using a superset of models in a bundle.

## Decision

### Per-Fleet ASGs with Spot Instances

Each fleet gets its own Auto Scaling Group (ASG) because each fleet can have a distinct bundle of models. Fleets provide a shared pool of GPU workers for many workflows while keeping AMIs and bundles aligned per fleet.

All instances use **Spot pricing** (60-70% savings over On-Demand). Warm pools are not used because they do not support Spot instances.

### ASG Configuration (per fleet)

```bash
aws autoscaling create-auto-scaling-group \
  --auto-scaling-group-name asg-<stage>-<fleet-slug> \
  --mixed-instances-policy '{
    "LaunchTemplate": {
      "LaunchTemplateSpecification": {
        "LaunchTemplateName": "lt-<stage>-<fleet-slug>",
        "Version": "$Latest"
      },
      "Overrides": [
        {"InstanceType": "g4dn.xlarge"}
      ]
    },
    "InstancesDistribution": {
      "OnDemandBaseCapacity": 0,
      "OnDemandPercentageAboveBaseCapacity": 0,
      "SpotAllocationStrategy": "capacity-optimized-prioritized",
      "SpotMaxPrice": ""
    }
  }' \
  --min-size 0 \
  --max-size 10 \
  --desired-capacity 0 \
  --vpc-zone-identifier "subnet-aaa,subnet-bbb,subnet-ccc" \
  --capacity-rebalance \
  --default-instance-warmup 300 \
  --tags Key=FleetSlug,Value=<fleet-slug>,PropagateAtLaunch=true
```

Key settings:
- `OnDemandBaseCapacity: 0` -- all Spot
- `capacity-optimized-prioritized` -- launches from deepest Spot pool
- `--capacity-rebalance` -- proactively replaces at-risk instances
- Multiple subnets (3 AZs) -- maximizes Spot pool diversity

### Dual Scaling Policy

1. **Step scaling** for 0->1: CloudWatch alarm on `QueueDepth > 0`
2. **Target tracking** for 1->N: `BacklogPerInstance` target = 2

### Scale-to-Zero

After 15 minutes of `QueueDepth == 0`, scale desired capacity to 0.

### Fleet Self-Registration

ASG instances self-register via `POST /api/worker/register` with a fleet secret (stored in SSM Parameter Store). Registration requires `fleet_slug` and `stage`. The backend resolves workflow IDs via the `comfyui_workflow_fleets` mapping, assigns them to the worker, and issues a per-worker auth token. On shutdown (SIGTERM or Spot interruption), workers call `POST /api/worker/deregister`.

This is the **only** registration path. Admin panel provides management (approve/revoke/drain/rotate-token/assign-workflows) but not worker creation.

Security controls:
- Rate limiting: 10 registrations/minute per IP
- Max fleet workers cap (configurable, default 50)
- Fleet secret via `X-Fleet-Secret` header
- `fleet_slug` required (validated against active fleets with assigned workflows)

### Spot Interruption Handling

Workers run a background thread polling EC2 instance metadata every 5 seconds for Spot interruption notices (2-minute warning). On interruption:

1. Worker detects via `http://169.254.169.254/latest/meta-data/spot/instance-action`
2. Worker calls `POST /api/worker/requeue` -- job goes back to `queued` without incrementing attempts
3. Worker deregisters and exits
4. ASG launches replacement Spot instance
5. New worker picks up the requeued job

### CloudWatch Metrics (8 per fleet)

Published every minute by `workers:publish-metrics` artisan command.

Dimensions:
- `FleetSlug=<fleet-slug>`
- `Stage=<stage>`

| Metric | Purpose |
|---|---|
| QueueDepth | Total queued + leased dispatches |
| BacklogPerInstance | QueueDepth / ActiveWorkers |
| ActiveWorkers | Workers seen in last 5 min |
| AvailableCapacity | Sum of (max_concurrency - current_load) |
| JobProcessingP50 | Median job duration (last 10 min) |
| ErrorRate | Failed / total jobs (last 5 min) |
| LeaseExpiredCount | Timed-out leases |
| SpotInterruptionCount | Jobs requeued due to Spot (last 5 min) |

### Stale Worker Cleanup

`workers:cleanup-stale` runs every 15 minutes, removing fleet workers not seen for 2+ hours.

### Cost Controls

| Control | Value |
|---|---|
| ASG max-size | 10 per fleet |
| All-Spot policy | OnDemandBaseCapacity=0 |
| AWS Budgets alarm | Alert at threshold |
| GPU Spot quota | Request increase for G/VT Spot |

### Scaling Timeline (Cold Start)

```
T+0:00   Job submitted
T+1:00   Scheduler publishes QueueDepth=1
T+2:00   CloudWatch alarm triggers
T+2:00   ASG requests Spot instance
T+5:00   Instance boots, worker registers
T+5:10   Worker picks up job
T+5:15   Processing begins
```

## Consequences

- **Cost:** ~60-70% savings with Spot (g4dn.xlarge: ~$0.16-0.20/hr vs $0.526/hr On-Demand)
- **Cold start:** ~3-5 min from zero (acceptable for async video jobs taking 1-30 min)
- **Reliability:** Spot interruptions handled via requeue (no job loss, no attempt penalty)
- **Complexity:** Per-fleet ASGs + workflow routing mapping + Spot monitoring adds operational surface
- **Scale limit:** DB-backed queue viable up to ~50-100 concurrent workers; consider SQS beyond that

## Files Changed

### Backend
- `database/migrations/2026_02_16_000001_add_fleet_fields_to_comfy_ui_workers.php` -- registration_source column
- `database/migrations/2026_02_16_000002_add_dispatch_poll_composite_index.php` -- poll query performance
- `app/Models/ComfyUiWorker.php` -- registration_source in fillable
- `config/services.php` -- fleet_secret, max_fleet_workers, stale_worker_hours
- `bootstrap/app.php` -- rate limiter, scheduler
- `app/Http/Middleware/EnsureFleetSecret.php` -- fleet secret validation
- `app/Http/Controllers/ComfyUiWorkerController.php` -- register(), deregister(), requeue()
- `app/Http/Controllers/Admin/WorkersController.php` -- management (no store(), fleet-only registration)
- `routes/api.php` -- fleet registration routes, requeue/deregister routes
- `routes/console.php` -- workers:publish-metrics, workers:cleanup-stale

### Worker (Python)
- `worker/comfyui_worker.py` -- fleet registration, Spot monitor thread, requeue, SIGTERM handler, scale-in protection

### Frontend
- `frontend/src/lib/api.ts` -- registration_source in AdminWorker type
- `frontend/src/app/admin/workers/page.tsx` -- Fleet/Admin badges

### Tests
- `tests/Feature/FleetRegistrationTest.php` -- registration, deregistration, requeue tests

## Future Evolution

| Phase | Change | Trigger |
|---|---|---|
| 2 | IAM Instance Identity Document verification | Replace fleet secret |
| 3 | Add g5/g6 to ASG Overrides | After validating GPU compatibility |
| 4 | SQS per-workflow queues | Worker count > 100 |
| 5 | GPU metrics via CloudWatch agent | When GPU utilization monitoring needed |
