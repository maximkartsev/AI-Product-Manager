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
- Create database
- Install backend dependencies
- Generate application encryption key
- Run database migrations
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
echo "NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api" > .env.local

# Start dev server
pnpm dev
```

**Result:** Frontend available at `http://localhost:3000` making requests to backend at `http://localhost:8000/api`.

---

## Quick Reference

| Task | Command |
|------|---------|
| First-time setup | `make init` |
| Start containers | `cd laradock && docker compose -p bp up -d` |
| Stop containers | `cd laradock && docker compose -p bp down` |
| Start frontend | `cd frontend && pnpm dev` |
| Verify mounts | `docker exec -it bp-workspace-1 ls /var/www` |

## Access Points

After starting containers:

- **Backend API:** `http://localhost:8000`
- **Frontend Dev:** `http://localhost:3000`
- **Database:** `127.0.0.1:3306` (username: `root`, password: `root`)
