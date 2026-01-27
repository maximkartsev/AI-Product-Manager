# ADR-0001: Subdomain tenants + pooled tenant DBs + central public DB

## Status

Accepted.

## Context

The product requires:

- strict isolation of private user data (tenant boundary)
- a public/shared area (e.g. Explore/Public Gallery) accessible without tenant initialization
- horizontal scaling of tenant-private persistence (sharding)
- tolerance for denormalization for public reads (no cross-DB joins)

## Decision

- Use **Stancl Tenancy** for tenant identification and context lifecycle.
  - Tenant is resolved by **domain/subdomain**
  - Tenancy metadata is stored in the **central DB** (`tenants`, `domains`)
- Store **public/shared** (GLOBAL + USER_SHARED) data in the **central DB**.
  - Public reads must not require tenant initialization
  - Public gallery uses a **denormalized central read model** (`gallery_videos`)
- Store **tenant-private** (USER_PRIVATE + TENANT) data in **pooled tenant databases**.
  - Tenants are horizontally sharded across pool DBs
  - A custom tenancy bootstrapper routes the logical `tenant` connection to the correct pool using `tenants.db_pool`
  - Tenant-private tables include `tenant_id` and are scoped with `BelongsToTenant` within each pooled DB

## Consequences

- No DB-level foreign keys from tenant pools to central tables.
  - Cross-DB references (e.g. `user_id`, `effect_id`) must be validated in application logic.
- Public pages avoid cross-DB joins by using denormalized snapshots.
- Operational requirement: run tenant migrations **per pool**.

## Implementation pointers

- **DB connections**: `backend/config/database.php` (`central`, `tenant_pool_*`, logical `tenant`)
- **Tenancy config**: `backend/config/tenancy.php` (custom DB pool bootstrapper)
- **DB pool bootstrapper**: `backend/app/Tenancy/Bootstrappers/DatabasePoolTenancyBootstrapper.php`
- **Tenant routing safety**: `backend/app/Http/Middleware/EnsureTenantMatchesUser.php`
- **Migration tooling**: `php artisan tenancy:pools-migrate`

