# ADR-0004: ComfyUI worker routing + leased dispatch queue

## Status

Accepted. Updated 2026-02-15 (provider unification + strict workflow enforcement).

## Context

- AI processing runs on **external GPU workers** (ComfyUI).
- Tenants are **pooled across tenant DBs**; `ai_jobs` live in tenant pools.
- Two provider types: **self_hosted** (local ComfyUI at localhost:8188) and **cloud** (cloud.comfy.org API).
- Private user videos must remain **tenant-private**; binaries live in **S3-compatible object storage**.
- We need **safe retries** (worker failures, network loss) and **token safety** (reserve/consume/refund).

## Decision

### Unified provider model

Two providers only:
- **`self_hosted`** — worker runs ComfyUI locally (localhost:8188). Used by ASG GPU instances.
- **`cloud`** — worker proxies to Comfy Cloud API. Uses `COMFY_CLOUD_API_KEY`.

Previously existed `local` (renamed to `self_hosted`) and `managed` (deleted — never integrated).

### Control plane vs data plane

- **Control plane (Laravel backend)**:
  - Owns AI job lifecycle, routing, leases, idempotency, and S3 pre-signed URL generation.
  - Writes tenant-private records (`ai_jobs`, `videos`, `files`) and central dispatch rows.
- **Data plane (ComfyUI workers)**:
  - A lightweight worker agent polls the backend for jobs.
  - Uses **pre-signed GET/PUT URLs** for input/output assets.

### Central dispatch queue

Because `ai_jobs` live in tenant pools, **routing cannot scan all pools**. We create a **central**
dispatch table that represents each tenant job once and drives worker selection.

Central table: `ai_job_dispatches`
- `tenant_id`, `tenant_job_id`, `status` (`queued|leased|completed|failed`)
- `lease_token`, `lease_expires_at`, `worker_id`, `attempts`, `priority`, `provider`
- `workflow_id` (NOT NULL) — strict binding to a specific workflow

### Strict workflow enforcement

Every dispatch has a `workflow_id`. Workers only receive jobs matching their assigned workflows.

- **Effects** must have a `workflow_id` (NOT NULL). An effect without a workflow cannot create jobs.
- **Dispatches** must have a `workflow_id` (NOT NULL). Orphaned dispatches are cleaned up.
- **Workers** register with `fleet_slug` + `stage`; backend resolves active workflow assignments from `comfyui_workflow_fleets`. A worker with no mapped active workflows gets zero jobs.
- **leaseDispatch()** uses strict `whereIn('workflow_id', $workflowIds)` — no `orWhereNull` fallback.

This prevents a face-swap worker from receiving an upscale job (wrong models loaded = crash + wasted GPU time).

### Worker registry + health

Central table: `comfyui_workers`
- `worker_id`, `capabilities`, `max_concurrency`, `registration_source` (`fleet`)
- `last_seen_at`, `is_draining`, `is_approved`

### Single registration path

All workers register via fleet self-registration:
```
POST /api/worker/register
Header: X-Fleet-Secret: <secret>
Body: { worker_id, fleet_slug, stage, max_concurrency, ... }
Response: { token, worker_id, workflows_assigned }
```

Admin panel provides management (approve/revoke/drain/rotate-token) and read-only visibility into fleet-derived assignments, but not worker creation or manual workflow assignment.

### Worker protocol (pull-based)

Seven endpoints on the backend. Registration uses a shared fleet secret; all others use the per-worker bearer token returned at registration.

| Endpoint | Auth | Purpose |
|---|---|---|
| `POST /api/worker/register` | `X-Fleet-Secret` | Fleet self-registration. Returns bearer token + assigned workflows. |
| `POST /api/worker/poll` | Bearer token | Poll for a job. Backend leases a dispatch row matching worker's workflows/providers. |
| `POST /api/worker/heartbeat` | Bearer token | Extend lease TTL while job is running. |
| `POST /api/worker/complete` | Bearer token | Report success. Backend updates tenant job/video/file, consumes tokens. |
| `POST /api/worker/fail` | Bearer token | Report failure. Backend updates tenant job/video, refunds tokens. |
| `POST /api/worker/requeue` | Bearer token | Return job to queue without counting an attempt (Spot interruption, capacity rebalance). |
| `POST /api/worker/deregister` | Bearer token | Remove worker from registry on shutdown. Detaches workflow assignments, deletes record. |

**Lease flow detail:**
1. Worker sends `worker_id`, `capabilities`, `current_load`, `max_concurrency`, `providers`.
2. Backend calls `leaseDispatch()`: inside a DB transaction with `lockForUpdate`, selects the highest-priority `queued` row (or expired lease) matching the worker's `workflow_ids` and `providers`, increments `attempts`, assigns a UUID `lease_token` + expiry.
3. Backend initializes tenant context, builds job payload with pre-signed URLs, returns payload to worker.
4. Worker extends lease via heartbeat while processing.
5. Worker reports complete/fail; backend updates tenant records and applies `consumeForJob()` or `refundForJob()`.

### Storage access

Workers never receive long-lived credentials. The backend issues **pre-signed URLs**:
- `GET` for original input video
- `PUT` for processed output video

For Comfy Cloud jobs, the worker uploads inputs via Cloud Assets API and downloads outputs
via `/api/view`, then stores final results in S3.

## Consequences

- **Self-hosted workers** require only outbound access (polling).
- **At-least-once** processing with leases and retries.
- **No cross-DB joins**: central queue drives routing, tenant DB holds job state.
- **Token safety** remains centralized in the existing `TokenLedgerService`.
- **Strict workflow binding** prevents model mismatch crashes.

## Flow diagram

```mermaid
flowchart TD
  Client[ClientApp] --> UploadInit[Backend:CreateUpload]
  UploadInit --> S3Put[Client:PUTToS3Presigned]
  S3Put --> FileCreate[Backend:CreateFile+Video]
  FileCreate --> SubmitJob[Backend:POST/ai-jobs]
  SubmitJob --> Reserve[TokenLedger:reserveForJob]
  SubmitJob --> DispatchRow[Central:ai_job_dispatches status=queued]

  Worker[ComfyWorker] --> Poll[Backend:WorkerPoll]
  Poll --> Lease[Central:leaseDispatchRow]
  Lease --> InitTenant[Backend:initTenancy(tenant_id)]
  InitTenant --> JobPayload[Backend:return job + presigned GET/PUT]

  JobPayload --> Run[Worker:RunComfyUI]
  Run --> UploadOut[Worker:PUTOutputToS3Presigned]
  UploadOut --> Complete[Worker:ReportComplete]
  Complete --> UpdateTenant[Backend:update videos/files/ai_jobs]
  UpdateTenant --> Consume[TokenLedger:consumeForJob]
```

## Implementation pointers

- AI job submission: `backend/app/Http/Controllers/AiJobController.php`
- Queued dispatch creation (fallback): `backend/app/Jobs/ProcessAiJob.php` — Laravel queued job that ensures a dispatch row exists for a given tenant AI job
- Worker polling/leasing: `backend/app/Http/Controllers/ComfyUiWorkerController.php`
- Workflow payload builder: `backend/app/Services/WorkflowPayloadService.php`
- Tenancy bootstrapper: `backend/app/Tenancy/Bootstrappers/DatabasePoolTenancyBootstrapper.php`
- Dispatch + worker tables: `backend/database/migrations/*`
- Tenant video/file tables: `backend/database/migrations/tenant/*`
