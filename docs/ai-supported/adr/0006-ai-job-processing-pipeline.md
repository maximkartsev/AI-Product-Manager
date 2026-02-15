# ADR-0006: AI Job Processing Pipeline (End-to-End)

**Status:** Accepted
**Date:** 2026-02-15

## Context

This ADR documents the complete end-to-end AI job processing pipeline — from user upload through worker execution to result delivery. It supersedes earlier drafts and reflects the current architecture after provider unification and strict workflow enforcement (see ADR-0004, ADR-0005).

---

## 1. Architecture Overview

```
┌──────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  Next.js     │────▶│  Laravel Backend  │────▶│  S3-compatible   │
│  Frontend    │◀────│  (Control Plane)  │◀────│  Object Storage  │
└──────────────┘     └────────┬─────────┘     └──────────────────┘
                              │
                     ┌────────┴─────────┐
                     │  Central DB       │
                     │  ai_job_dispatches│
                     │  comfyui_workers  │
                     │  worker_workflows │
                     └────────┬─────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
       ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
       │ EC2 (GPU)   │ │ EC2 (GPU)   │ │ EC2 (GPU)   │
       │ Worker +    │ │ Worker +    │ │ Worker +    │
       │ ComfyUI     │ │ ComfyUI     │ │ ComfyUI     │
       └─────────────┘ └─────────────┘ └─────────────┘
           ASG (per-workflow horizontal scaling)
```

**Key design decisions:**
- **1 Python worker per EC2 instance**, sequential processing (`MAX_CONCURRENCY=1` typical).
- **ASG horizontal scaling** — per-workflow Auto Scaling Groups scale GPU instances based on queue depth.
- **Dual-database model** — central DB holds dispatch queue, workers, workflows, effects; tenant DB holds `ai_jobs`, `videos`, `files`, `token_wallets`, `token_transactions`.
- **Pull-based protocol** — workers poll the backend; backend never pushes to workers.
- **Two providers only**: `self_hosted` (localhost ComfyUI) and `cloud` (cloud.comfy.org API).

---

## 2. Job Creation Flow

Triggered when a user applies an effect to their uploaded video.

### Frontend sequence

1. User selects an effect and uploads a video on the effect page.
2. Frontend calls `POST /api/videos/uploads` → gets presigned PUT URL + file record.
3. Frontend uploads the video binary directly to S3 via the presigned PUT URL (with XHR progress tracking).
4. Frontend calls `POST /api/videos` to create the Video record (links `original_file_id`).
5. Frontend calls `POST /api/ai-jobs` with `effect_id`, `video_id`, and `idempotency_key`.

### Backend: `AiJobController.store()`

1. **Validate** — effect exists, has a `workflow_id` (NOT NULL required), user owns the video/file.
2. **Idempotency check** — if a job with the same `idempotency_key` already exists, return it (with dispatch re-ensured if still active).
3. **Concurrency guard** — max 5 active jobs (`queued`/`processing`) per user.
4. **Build input payload** — `WorkflowPayloadService` resolves properties (workflow defaults → effect overrides → user input), loads the ComfyUI workflow JSON from S3, replaces text placeholders, and builds the asset list for the worker.
5. **Reserve tokens** — inside a tenant DB transaction: create `AiJob` (status=`queued`), then `TokenLedgerService.reserveForJob()` deducts tokens from wallet and records a `JOB_RESERVE` transaction.
6. **Create dispatch** — `ensureDispatch()` creates an `AiJobDispatch` row in central DB (status=`queued`, copies `workflow_id` from Effect).

### `ProcessAiJob.php` (fallback)

A Laravel queued job (`ShouldQueue`) that ensures a dispatch row exists. Used as a safety net — if `ensureDispatch()` in the controller succeeds (normal path), this is a no-op via `firstOrCreate`. Located at `backend/app/Jobs/ProcessAiJob.php`.

---

## 3. Dispatch Queue & Routing

### Central table: `ai_job_dispatches`

| Column | Purpose |
|---|---|
| `tenant_id` | Links to tenant pool |
| `tenant_job_id` | Links to `ai_jobs.id` in tenant DB |
| `workflow_id` | NOT NULL — strict workflow binding |
| `provider` | `self_hosted` or `cloud` |
| `status` | `queued` → `leased` → `completed` or `failed` |
| `lease_token` | UUID assigned when leased, verified on heartbeat/complete/fail |
| `lease_expires_at` | Lease expiry; expired leases are re-polled |
| `worker_id` | Worker that holds the lease |
| `attempts` | Incremented on each lease; capped at `max_attempts` (default 3) |
| `priority` | Higher priority dispatched first |
| `duration_seconds` | Computed on completion from audit log poll timestamp |
| `last_error` | Last error message (from fail or requeue) |

### `leaseDispatch()` internals

Called inside `ComfyUiWorkerController.poll()` within a central DB transaction:

1. **`lockForUpdate`** — row-level pessimistic lock prevents double-leasing.
2. **Status filter** — selects rows where `status = 'queued'` OR (`status = 'leased'` AND `lease_expires_at <= now()`). Expired leases are reclaimed.
3. **Provider filter** — `whereIn('provider', $providers)` — worker declares which providers it supports.
4. **Workflow filter** — `whereIn('workflow_id', $workflowIds)` — strict match, no fallback. Workers with no workflow assignments get zero jobs.
5. **Attempt guard** — `attempts < max_attempts` (default 3).
6. **Priority ordering** — `orderByDesc('priority')`, then `orderBy('created_at')` (FIFO within same priority).
7. **Lease assignment** — sets `status='leased'`, assigns UUID `lease_token`, sets `lease_expires_at` (default 900s), increments `attempts`.

---

## 4. Worker Registration

**Single path: fleet self-registration.** Admin panel provides management (approve, revoke, drain, rotate token, assign workflows) but cannot create workers.

```
POST /api/worker/register
Header: X-Fleet-Secret: <shared secret>
Body: {
  worker_id: "i-abc123",        // typically EC2 instance ID
  workflow_slugs: ["face-swap"], // REQUIRED, min:1, must exist in workflows table
  max_concurrency: 1,
  capabilities: {...}
}
Response: {
  worker_id: "i-abc123",
  token: "<64-char random bearer token>",
  workflows_assigned: ["face-swap"]
}
```

**Registration logic** (`ComfyUiWorkerController.register()`):

1. Validate inputs; `workflow_slugs` must exist in `workflows` table.
2. Fleet worker count cap check (`max_fleet_workers` config, default 50).
3. Duplicate check — reject if `worker_id` already registered.
4. **ASG instance validation** — if `worker_id` starts with `i-` and `validate_asg_instance` config is enabled, calls AWS `describeAutoScalingInstances` to verify the instance belongs to a known ASG. Fails open if AWS API unreachable.
5. Generate SHA-256 hashed bearer token; resolve workflow slugs to IDs (active workflows only).
6. Create `comfyui_workers` record (`registration_source='fleet'`, `is_approved=true`), sync workflow pivot.
7. Audit log the registration event.

**Deregistration** (`POST /api/worker/deregister`): detaches workflow assignments, deletes worker record. Called by the Python worker on graceful shutdown.

---

## 5. Job Processing: `self_hosted` vs `cloud`

The Python worker (`worker/comfyui_worker.py`) runs a poll loop in `main()`. On receiving a job, `process_job()` branches by provider.

### Common preamble

1. Parse `dispatch_id`, `lease_token`, `input_url`, `output_url`, `output_headers`, `input_payload`.
2. Extract `output_node_id` from `input_payload` (tells worker which ComfyUI node produces the final output).
3. **Asset pipeline** — if `input_payload.assets` is present, call `download_and_upload_assets()`:
   - Download each asset from its presigned URL.
   - Upload to ComfyUI (local or cloud depending on provider).
   - Build a `placeholder_map` (placeholder string → ComfyUI filename).
   - Asset cache by `(endpoint, content_hash)` avoids re-uploading unchanged assets.

### `self_hosted` path

1. Download primary input via presigned GET URL → temp file.
2. `prepare_workflow()` — inject input file path and asset placeholders into the ComfyUI workflow JSON.
3. `run_comfyui()` — POST to `localhost:8188/prompt`, then poll `/history/{prompt_id}` every 2s until outputs appear (1h timeout).
4. `extract_output_file()` — find the output from the target node (checks `videos`, `gifs`, `images`, `files` keys).
5. `download_comfyui_output()` — GET from `localhost:8188/view` → temp file.
6. Upload output to S3 via presigned PUT URL.
7. Call `POST /api/worker/complete` with output metadata.
8. Clean up temp files in `finally` block.

### `cloud` path

1. If no asset pipeline handled the primary input: download input → upload to Comfy Cloud via `POST /api/upload/image`.
2. `prepare_workflow()` — inject cloud asset references (with empty `input_reference_prefix`).
3. `cloud_submit_prompt()` — POST to `cloud.comfy.org/api/prompt`.
4. `cloud_wait_for_job()` — poll `/api/job/{prompt_id}/status` every 2s until `completed`/`success` (1h timeout).
5. `cloud_fetch_outputs()` — GET `/api/history_v2/{prompt_id}`.
6. `cloud_download_output()` — GET `/api/view` with filename/subfolder params → temp file.
7. Upload output to S3 via presigned PUT URL.
8. Call `POST /api/worker/complete`.
9. Clean up temp files.

### Heartbeat & Spot interruption

- Background heartbeat extends lease every `HEARTBEAT_INTERVAL_SECONDS` (default 30s).
- Spot monitor thread polls EC2 instance metadata (`/meta-data/spot/instance-action`) every 5s.
- On Spot interruption or SIGTERM: set `_shutdown_requested`, requeue the current job (`POST /api/worker/requeue`), deregister, set ASG scale-in protection off.
- ASG scale-in protection is enabled while processing a job, disabled when idle.

---

## 6. Input/Output File Handling

### Presigned URLs

Generated by `PresignedUrlService` using Laravel's `Storage::disk()->temporaryUrl()` (download) and `temporaryUploadUrl()` (upload). TTL matches lease TTL (default 900s).

### S3 path pattern

Output files: `tenants/{tenant_id}/ai-jobs/{job_id}/output-{uuid}.{extension}`

Created by `ComfyUiWorkerController.ensureOutputFile()` when building the job payload.

### Asset pipeline

`WorkflowPayloadService.buildJobPayload()` prepares the asset list:
- **Primary input** — the user's uploaded video. Added to `assets[]` with `is_primary_input: true`, `s3_path`, `s3_disk`.
- **Effect assets** — images/videos configured on the workflow (e.g., overlay masks). Added with `content_hash` for caching.
- **Text properties** — replaced directly in the workflow JSON via placeholder substitution.

The backend pre-signs download URLs for each asset when building the poll response (`buildJobPayload()` in `ComfyUiWorkerController`). The worker downloads and uploads them to ComfyUI before running the prompt.

### Temp file cleanup

Worker uses `_safe_unlink()` in `finally` blocks to remove temp files for both input and output. Asset downloads are cleaned immediately after upload to ComfyUI.

---

## 7. Job Completion

`ComfyUiWorkerController.complete()` flow:

1. Validate `dispatch_id` + `lease_token`.
2. **`withTenant()`** — resolve Tenant, call `app(Tenancy::class)->initialize($tenant)` in try/finally with `->end()`.
3. Find `AiJob` in tenant DB. Guard: if already `completed`, just mark dispatch completed. If already `failed`, mark dispatch failed.
4. Set `AiJob.status = 'completed'`, `completed_at = now()`, save `provider_job_id` if provided.
5. **Update output File** — if `output_file_id` exists, update `size`, `mime_type`, `metadata`, generate `url` from Storage disk.
6. **Update Effect timing** — compute `last_processing_time_seconds` from `started_at → completed_at`, write to `effects` table (used by frontend for progress estimation).
7. **Update Video** — set `status='completed'`, `processed_file_id`, `processing_details`.
8. **Consume tokens** — `TokenLedgerService.consumeForJob()` records a `JOB_CONSUME` transaction (amount=0, serves as completion marker; the actual deduction happened at reserve time).
9. **Mark dispatch completed** — set `status='completed'`, compute `duration_seconds` from audit log poll timestamp.
10. **Output validation** (non-blocking) — `OutputValidationService.validate()` checks the output file exists on disk. Failures are logged but don't affect the job status.
11. **Audit log** — record `complete` event.

---

## 8. Job Failure & Error Handling

### `fail()` flow

1. Validate `dispatch_id` + `lease_token`.
2. **`sanitizeWorkerError()`** — parse worker error message: try JSON decode (`exception_message` or `error_message` fields), regex extraction, or first line of raw text. Fallback: `"Processing failed."`.
3. `withTenant()` → find AiJob. Guard: if already completed/failed, mark dispatch accordingly.
4. Set `AiJob.status = 'failed'`, `error_message = sanitized error`, `completed_at = now()`.
5. Update Video: `status='failed'`, `processing_details = {error: message}`.
6. **Refund tokens** — `TokenLedgerService.refundForJob()` records a `JOB_REFUND` transaction, increments wallet balance by `reserved_tokens` amount.
7. Mark dispatch failed, audit log.

### `requeue()` flow

Used for infrastructure interruptions (Spot termination, capacity rebalance) — **not** application errors.

1. Validate `dispatch_id` + `lease_token` + `reason`.
2. Reset dispatch: `status='queued'`, clear `worker_id`, `lease_token`, `lease_expires_at`.
3. Set `last_error = 'Requeued: {reason}'`.
4. **Decrement attempts** (if > 0) — requeue doesn't count against the max attempts limit.
5. Audit log with reason.

The job becomes available for another worker to pick up on the next poll cycle.

### Error propagation

Worker errors flow: Python worker `process_job()` exception → `fail_job()` → backend `fail()` → `AiJob.error_message` + `Video.processing_details.error` → frontend polls `GET /api/videos/{id}` → displays error banner.

---

## 9. Result Delivery to End User

The frontend (`ProcessingClient.tsx`) uses client-side polling:

1. After submitting the AI job, frontend begins polling `GET /api/videos/{id}` **every 2 seconds** via `setTimeout` (not `setInterval` — waits for each response before scheduling next).
2. **Terminal statuses**: `completed`, `failed`, `expired` — polling stops.
3. **Progress simulation** — frontend estimates progress based on `Effect.last_processing_time_seconds` (updated after each successful job). Four visual processing steps animate over the estimated duration.
4. **On `completed`** — Video response includes `processed_file_url` (S3 presigned URL). Frontend transitions to result view with video player.
5. **On `failed`** — Video response includes `error` message (from `processing_details.error`). Frontend shows error banner with "Upload another" CTA.
6. **Network resilience** — if a poll request fails, frontend shows a poll notice but keeps retrying. If previous video data exists, it continues showing it while retrying in the background.

---

## 10. Token Lifecycle

Tokens follow a three-phase lifecycle with idempotency guards on each transition:

```
PAYMENT_CREDIT (wallet top-up)
    ↓
JOB_RESERVE  ──────────────▶  JOB_CONSUME (on success)
  (deducts from wallet)           (amount=0, marker only)
    │
    └──────────────────────▶  JOB_REFUND  (on failure)
                                (restores to wallet)
```

| Transaction type | Amount | When | Idempotency key |
|---|---|---|---|
| `JOB_RESERVE` | `-N` (deducted) | Job submission in `AiJobController.store()` | `tenant_id` + `job_id` + type |
| `JOB_CONSUME` | `0` (marker) | Job completion in `complete()` | Same |
| `JOB_REFUND` | `+N` (restored) | Job failure in `fail()`, or dispatch enqueue failure | Same |

**Safety guarantees:**
- All token operations run inside `DB::connection('tenant')->transaction()` with `lockForUpdate` on the wallet row.
- Each phase checks for existing transactions of the same type+job before creating — prevents double-reserve, double-consume, double-refund.
- `reserveForJob()` checks `wallet.balance >= tokenCost` and throws `'Insufficient token balance.'` if insufficient, which the controller catches and returns as a 422 with `required_tokens`.

---

## 11. Execution Model Diagram

```
                                FRONTEND
                                   │
                    ┌──────────────┼──────────────┐
                    ▼              ▼              ▼
              Upload to S3    POST /ai-jobs   Poll GET /videos/{id}
              (presigned PUT)  (create job)    (every 2s)
                    │              │
                    ▼              ▼
               ┌────────────────────────────────────┐
               │           LARAVEL BACKEND           │
               │                                     │
               │  AiJobController.store()            │
               │    ├─ Validate effect + workflow     │
               │    ├─ TokenLedger.reserveForJob()   │
               │    ├─ Create AiJob (tenant DB)      │
               │    └─ Create AiJobDispatch (central) │
               │                                     │
               │  ComfyUiWorkerController.poll()     │
               │    ├─ leaseDispatch() [lockForUpdate]│
               │    ├─ initTenancy → buildJobPayload │
               │    └─ Return presigned GET/PUT URLs │
               │                                     │
               │  ComfyUiWorkerController.complete() │
               │    ├─ Update AiJob + Video + File   │
               │    ├─ TokenLedger.consumeForJob()   │
               │    └─ markDispatchCompleted()       │
               └──────────────┬──────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
       ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
       │ EC2 Instance │ │ EC2 Instance │ │ EC2 Instance │
       │             │ │             │ │             │
       │ Python      │ │ Python      │ │ Python      │
       │ Worker      │ │ Worker      │ │ Worker      │
       │   │         │ │   │         │ │   │         │
       │   ▼         │ │   ▼         │ │   ▼         │
       │ ComfyUI     │ │ ComfyUI     │ │ ComfyUI     │
       │ (GPU)       │ │ (GPU)       │ │ (GPU)       │
       └──────┬──────┘ └──────┬──────┘ └──────┬──────┘
              │               │               │
              └───────────────┼───────────────┘
                              │
                              ▼
                     ┌──────────────────┐
                     │  S3 Object Store  │
                     │  (input/output)   │
                     └──────────────────┘

ASG per workflow:
  face-swap ASG  ──▶  [i-001, i-002]  (workflow_slugs=["face-swap"])
  upscale ASG    ──▶  [i-003]         (workflow_slugs=["upscale"])
```

---

## Key Tables

| Table | DB | Key columns |
|---|---|---|
| `effects` | central | `workflow_id` (NOT NULL), `credits_cost`, `last_processing_time_seconds` |
| `ai_job_dispatches` | central | `workflow_id` (NOT NULL), `provider`, `status`, `lease_token`, `priority`, `attempts` |
| `comfyui_workers` | central | `worker_id`, `registration_source` (`fleet`), `is_approved`, `is_draining` |
| `worker_workflows` | central | `worker_id`, `workflow_id` (pivot) |
| `workflows` | central | `id`, `slug` (unique), `is_active`, `properties`, `comfyui_workflow_path` |
| `ai_jobs` | tenant pool | `effect_id`, `provider`, `status`, `input_file_id`, `output_file_id`, `idempotency_key` |
| `token_wallets` | tenant pool | `tenant_id`, `user_id`, `balance` |
| `token_transactions` | tenant pool | `type`, `amount`, `job_id`, `provider_transaction_id` |
| `videos` | tenant pool | `status`, `original_file_id`, `processed_file_id`, `processing_details` |
| `files` | tenant pool | `disk`, `path`, `mime_type`, `size`, `url` |

## Key Files

| Component | File |
|---|---|
| Job submission | `backend/app/Http/Controllers/AiJobController.php` |
| Worker protocol (7 endpoints) | `backend/app/Http/Controllers/ComfyUiWorkerController.php` |
| Worker management (admin) | `backend/app/Http/Controllers/Admin/WorkersController.php` |
| Dispatch creation fallback | `backend/app/Jobs/ProcessAiJob.php` |
| Python worker | `worker/comfyui_worker.py` |
| Workflow payload builder | `backend/app/Services/WorkflowPayloadService.php` |
| Token ledger | `backend/app/Services/TokenLedgerService.php` |
| Presigned URLs | `backend/app/Services/PresignedUrlService.php` |
| Output validation | `backend/app/Services/OutputValidationService.php` |
| Worker audit logging | `backend/app/Services/WorkerAuditService.php` |
| Frontend processing page | `frontend/src/app/effects/[slug]/processing/ProcessingClient.tsx` |
| Config | `backend/config/services.php` (comfyui section) |
| Routes | `backend/routes/api.php` |

## Consequences

- **Safety**: Workers cannot receive mismatched jobs (strict workflow binding via `whereIn`)
- **Simplicity**: Two providers (`self_hosted`, `cloud`), one registration path (fleet), no dead code
- **Auditability**: Worker audit logs track registration, polling, completion, failure, requeue, deregistration
- **Token safety**: Reserve/consume/refund with idempotency guards and wallet locking
- **Scalability**: Per-workflow ASGs with independent scaling (see ADR-0005)
- **Resilience**: Lease expiry reclaims stuck jobs; Spot interruption requeues without attempt penalty; presigned URLs avoid long-lived credentials
