# Quick Start Guide

This guide provides the essential steps to get your project up and running quickly.

## Prerequisites

- Docker Desktop installed and running
- WSL2 enabled with Ubuntu
- Docker Desktop WSL Integration enabled

## Step 1: Run Commands from WSL

### Open WSL Terminal

```bash
wsl
```

### Navigate to Project

```bash
cd /mnt/c/Projects/AI-Product-Management/AI-Product-Manager
```

### Initialize (First Time)

```bash
make init
```

This will set up everything automatically:
- Initialize git submodules (Laradock)
- Set up environment files
- Build Docker containers
- Start containers
- Create central + tenant-pool databases
- Install backend dependencies
- Generate application encryption key
- Run database migrations (central + tenant pools)
- Install frontend dependencies (if needed)

### Start Containers (After Setup)

```bash
cd laradock
docker compose -p bp up -d
```

## Step 2: Verify Mounts

Check if backend code is mounted correctly:

```bash
docker exec -it bp-workspace-1 ls /var/www
```

**Expected output:** `app/`, `config/`, `routes/`, etc.

If you see your Laravel application files, the volume mount is working correctly.

## Step 3: Start Frontend

The frontend runs locally for development (no Docker needed):

```bash
cd /mnt/c/Projects/AI-Product-Management/AI-Product-Manager/frontend

# Create .env.local
echo "NEXT_PUBLIC_API_BASE_URL=http://<your-tenant-subdomain>.localhost:80/api" > .env.local

# Start dev server (recommended on WSL when the repo is under /mnt/c)
# - Next.js 16 uses Turbopack by default, but file watching on /mnt/c can be unreliable.
# - Webpack + polling makes hot reload reliable on WSL-mounted Windows filesystems.
WATCHPACK_POLLING=true pnpm exec next dev --webpack
```

**How to get your tenant subdomain**

- Call `POST http://localhost:80/api/register` (central) — the response includes `data.tenant.domain` (e.g. `bob1.localhost`).
- Use that domain as your API base: `http://bob1.localhost:80/api` (tenant routes require tenant initialization).

**Result:** Frontend available at `http://localhost:3000` making requests to the tenant API at `http://<tenant>.localhost:80/api`.

---

## Step 4: Database Migrations

### Run Migrations Manually

After making changes to migrations, run:

```bash
cd laradock
docker compose exec workspace bash -c "cd /var/www && php artisan tenancy:pools-migrate"
```

This command migrates:
- **Central database** (`database/migrations/`)
- **All tenant pool databases** (`database/migrations/tenant/`)

**Note:** The standard `php artisan migrate` command will only migrate the central database. Use `tenancy:pools-migrate` to migrate both central and tenant pools.

### Environment Variables

For **local development** with Laradock, you typically don't need to set tenant pool-specific environment variables. The configuration falls back to defaults:

- `TENANT_POOL_1_DB_HOST` → Falls back to `DB_HOST` → Defaults to `127.0.0.1`
- `TENANT_POOL_2_DB_HOST` → Falls back to `DB_HOST` → Defaults to `127.0.0.1`

**Only set these if:**
- You're using remote databases for tenant pools
- You need different hosts/ports for different pools
- You're deploying to production

Example for remote tenant pools:
```env
TENANT_POOL_1_DB_HOST=db.example.com
TENANT_POOL_1_DB_PORT=3306
TENANT_POOL_1_DB_DATABASE=tenant_pool_1
TENANT_POOL_1_DB_USERNAME=myuser
TENANT_POOL_1_DB_PASSWORD=mypassword
```

## Quick Reference

| Task | Command |
|------|---------|
| First-time setup | `make init` |
| Start containers | `cd laradock && docker compose -p bp up -d` |
| Stop containers | `cd laradock && docker compose -p bp down` |
| Run migrations | `cd laradock && docker compose exec workspace bash -c "cd /var/www && php artisan tenancy:pools-migrate"` |
| Start frontend | `cd frontend && pnpm dev` |
| Verify mounts | `docker exec -it bp-workspace-1 ls /var/www` |

## Access Points

After starting containers:

- **Backend API:** `http://localhost:80`
- **Tenant API example:** `http://alice1.localhost:80`
- **Frontend Dev:** `http://localhost:3000`
- **Database:** `127.0.0.1:3306` (username: `root`, password: `root`)
