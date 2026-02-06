# Database (How to use the schema docs)

## Source of truth

The detailed schema spec is maintained in:

- `docs/init/database.md`

This file adds **implementation rules** for the project’s multi-DB tenancy architecture.

## Persistence zones (authoritative)

This project uses **subdomain-based multi-tenancy**, where **each user is a tenant**.
Many tenants share the same **pooled tenant DB**; isolation is enforced by `tenant_id` in each
tenant table and Stancl’s `BelongsToTenant` scoping.

- **Central DB (shared; no tenant init required)**
  - Hosts **GLOBAL** + **USER_SHARED** product data and the public “Explore/Gallery”.
  - Hosts tenancy metadata (`tenants`, `domains`) and auth (`users`, `personal_access_tokens`).
  - Hosts **payment/billing** entities (`purchases`, `payments`, `payment_events`, `subscriptions`) for global
    settlement and webhook safety.
  - Hosts infra tables like `jobs`/`failed_jobs`/`cache` (so queues & cache stay stable regardless of tenant DB routing).

- **Tenant pool DBs (sharded; tenant init required)**
  - Host **USER_PRIVATE** + **TENANT** data.
  - Multiple tenants live in a single pool DB.
  - Tenants are distributed across pools via `tenants.db_pool`.

> Important: because tenant-private tables live in separate databases, **DB-level foreign keys cannot reference central tables**. References like `user_id`, `effect_id` remain as columns, but are validated/enforced in application logic.

## Connection naming (code-level contract)

- **Central connection**: `central`
- **Tenant pool connections**: `tenant_pool_1`, `tenant_pool_2`, ... (list is environment/config-driven)
- **Tenant alias connection**: `tenant` (dynamically pointed at the correct `tenant_pool_*` per-request/job by a tenancy bootstrapper using `tenants.db_pool`)

## Data placement rules (all tables)

### Central DB tables

#### Tenancy + auth + infra (central)

| Table | Placement | Notes |
|------|-----------|------|
| `tenants` | central | `id` is the tenant identifier (slug + numeric suffix). Must include `user_id` and `db_pool`. |
| `domains` | central | Maps `domain` → `tenant_id` (e.g. `alice1.localhost`). |
| `users` | central | Auth identity. (Each user maps 1:1 to a tenant.) |
| `personal_access_tokens` | central | Sanctum tokens. Extended with device metadata columns used in `RegisterController`. |
| `jobs`, `job_batches`, `failed_jobs` | central | Queue storage (database driver). |
| `cache`, `cache_locks` | central | Cache storage (database driver) if enabled. |
| `sessions`, `password_reset_tokens` | central | If used. |

#### Product schema (from `docs/init/database.md`)

| Table | Scope | Placement | Rules/notes |
|------|-------|-----------|------------|
| `users` | GLOBAL | central | Central auth identity; do not require tenant init. |
| `discounts` | GLOBAL | central | Public/catalog. |
| `categories` | GLOBAL | central | Public/catalog. |
| `tiers` | GLOBAL | central | Public/catalog. |
| `algorithms` | GLOBAL | central | Internal catalog. |
| `filters` | GLOBAL | central | Public/catalog. |
| `models` | GLOBAL | central | Internal catalog. |
| `styles` | GLOBAL | central | Public/catalog. |
| `effects` | GLOBAL | central | Public/catalog; accessed by all tenants. |
| `packages` | GLOBAL | central | Public/catalog. |
| `tags` | GLOBAL | central | Public/catalog tagging system. |
| `overlays` | GLOBAL | central | Public/catalog. |
| `watermarks` | GLOBAL | central | Public/catalog. |
| `gallery_videos` | USER_SHARED | central | **Denormalized** public content. Must be readable without tenant init; no cross-DB joins. |
| `purchases` | USER_PRIVATE | central | Central billing record; includes `tenant_id` + `user_id`. |
| `payments` | USER_PRIVATE | central | Linked to `purchase_id` only; no `user_id`. Token credit happens in tenant DB after success. |
| `payment_events` | USER_PRIVATE | central | Raw provider webhook events for idempotency; unique by `provider_event_id`. |
| `subscriptions` | USER_PRIVATE | central | Subscription state is centralized for billing + renewals. |

### Tenant pool DB tables

All tenant-pool tables MUST include:

- `tenant_id` (string, indexed) — used by Stancl’s single-database scoping (`BelongsToTenant`) inside a pooled DB.
- No cross-DB FKs (e.g. `user_id` references central `users`, `effect_id` references central `effects`), enforced in code.

| Table | Scope | Placement | Required rules |
|------|-------|-----------|----------------|
| `token_wallets` | USER_PRIVATE | tenant-pool | Requires `tenant_id`. One wallet per tenant; `user_id` matches the tenant’s central user. |
| `token_transactions` | USER_PRIVATE | tenant-pool | Requires `tenant_id`. Append-only ledger for credits + job reservations/consumption; idempotent by provider transaction id. |
| `ai_jobs` | USER_PRIVATE | tenant-pool | Requires `tenant_id`. AI processing jobs; token reservation/consumption tracked per job. |
| `videos` | USER_PRIVATE | tenant-pool | Requires `tenant_id`. `effect_id` references central catalog (no FK). |
| `exports` | USER_PRIVATE | tenant-pool | Requires `tenant_id`. |
| `rewards` | USER_PRIVATE | tenant-pool | Requires `tenant_id`. |
| `files` | USER_PRIVATE | tenant-pool | Requires `tenant_id`. Store S3 metadata; public URLs are denormalized into `gallery_videos` on publish. |

## Cross-DB denormalization (public gallery)

`gallery_videos` (central) is the **public** read model. On publish/unpublish:

- **Publish**: write/update central `gallery_videos` with a snapshot needed for Explore (no tenant DB reads at query-time):
  - `tenant_id`
  - `video_id` (tenant DB id; not an FK)
  - `effect_id` (central FK ok)
  - `title`, `tags`, `created_at`
  - `processed_file_url` (or equivalent public URL/asset pointer)
- **Unpublish**: mark `is_public=false` and/or soft delete in `gallery_videos`.

## Ownership relevance (USER_PRIVATE)

Tenant isolation is enforced primarily by:

- **Tenant context** (subdomain) + `tenant_id` scoping (`BelongsToTenant`) inside pooled tenant DBs.

Additionally, the repo contains baseline per-user ownership enforcement:

- `backend/config/ownership.php`
- `App\Models\Concerns\UserOwned`

This remains useful as an extra guardrail (and for future multi-user tenants), but **tenant scoping is the primary isolation boundary** in this architecture.

## Migrations layout (expected)

- **Central migrations**: `backend/database/migrations/*`
- **Tenant migrations**: `backend/database/migrations/tenant/*` (run for each pool connection)

