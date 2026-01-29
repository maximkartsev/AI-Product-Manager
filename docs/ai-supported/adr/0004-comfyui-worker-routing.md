# ADR-0004: ComfyUI worker routing + leased dispatch queue

## Status

Accepted.

## Context

- AI processing runs on **external GPU workers** (ComfyUI).
- Tenants are **pooled across tenant DBs**; `ai_jobs` live in tenant pools.
- Workers may run **cloud** or **self-hosted** (behind NAT).
- Private user videos must remain **tenant-private**; binaries live in **S3-compatible object storage**.
- We need **safe retries** (worker failures, network loss) and **token safety** (reserve/consume/refund).

## Decision

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

### Worker registry + health

Central table: `comfyui_workers`
- `worker_id`, `environment` (`cloud|self_hosted`), `capabilities`, `max_concurrency`
- `last_seen_at`, `is_draining`

### Worker protocol (pull-based)

1. **Poll**: `POST /api/worker/poll`
   - Worker sends `worker_id`, `capabilities`, `current_load`, `max_concurrency`, `providers`.
2. **Lease**: backend selects a queued dispatch row and returns a **lease token** + expiry.
3. **Heartbeat**: worker extends lease while running.
4. **Complete/Fail**: worker reports status; backend updates tenant `ai_jobs` and applies token
   `consumeForJob()` or `refundForJob()`.

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
- AI job worker: `backend/app/Jobs/ProcessAiJob.php` (or new orchestration service)
- Tenancy bootstrapper: `backend/app/Tenancy/Bootstrappers/DatabasePoolTenancyBootstrapper.php`
- Dispatch + worker tables: `backend/database/migrations/*`
- Tenant video/file tables: `backend/database/migrations/tenant/*`
