# How to Run Containers

This guide explains how to run the Docker containers for both backend and frontend.

## Prerequisites

- Docker Desktop installed and running
- WSL2 enabled (if on Windows)
- Make installed (`make --version`)
- pnpm installed (for frontend development)

## Method 1: Automated Setup (Recommended)

### First-Time Setup

From the project root directory:

```bash
make init
```

This single command will:
1. ✅ Initialize git submodules (Laradock)
2. ✅ Set up environment files
3. ✅ Build Docker containers
4. ✅ Start containers
5. ✅ Create database
6. ✅ Install backend dependencies
7. ✅ Run database migrations
8. ✅ Install frontend dependencies

### After Initial Setup

Once initialized, you can manage containers with:

**Start containers:**
```bash
cd laradock
docker compose -p bp up -d
```

**Stop containers:**
```bash
cd laradock
docker compose -p bp down
```

**View container logs:**
```bash
cd laradock
docker compose -p bp logs -f
```

**Restart containers:**
```bash
cd laradock
docker compose -p bp restart
```

## Method 2: Manual Container Management

### Backend Containers (Laradock)

The backend uses Laradock which includes:
- `workspace` - Development environment
- `php-fpm` - PHP-FPM service
- `nginx` - Web server
- `mariadb` - Database
- `redis` - Cache/session store

**Start backend containers:**
```bash
cd laradock
docker compose -p bp up -d workspace php-fpm nginx mariadb redis
```

**Stop backend containers:**
```bash
cd laradock
docker compose -p bp down
```

**Rebuild containers (after changes):**
```bash
cd laradock
docker compose -p bp build workspace php-fpm nginx mariadb redis
docker compose -p bp up -d workspace php-fpm nginx mariadb redis
```

### Frontend Containers

#### Option A: Development Mode (Recommended for Development)

Run the frontend locally with hot-reload:

```bash
cd frontend
pnpm install          # First time only
pnpm dev              # Starts Next.js dev server on http://localhost:3000
```

**Important:** Set the environment variable before running:
```bash
# Windows PowerShell
$env:NEXT_PUBLIC_API_BASE_URL="http://localhost:8000/api"
pnpm dev

# Windows CMD
set NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
pnpm dev

# Linux/WSL/Mac
export NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
pnpm dev
```

You can also use `NEXT_PUBLIC_API_URL` as an alias (same value, including `/api`).

Or create a `.env.local` file in the `frontend` directory:
```env
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
```

#### Option B: Production Mode (Docker)

Build and run frontend in Docker:

```bash
cd frontend

# Set environment variable
export NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api

# Build and start
docker compose -f docker-compose.prod.yml up -d --build
```

The frontend will be available at `http://localhost:3002` (mapped from container port 3000).

**Stop frontend container:**
```bash
cd frontend
docker compose -f docker-compose.prod.yml down
```

## Container Access

### Access Workspace Container (Backend)
```bash
docker exec -it bp-workspace-1 bash
```

Inside the container, you can run:
- `php artisan` - Laravel commands
- `composer` - Composer commands
- `npm` / `pnpm` - Node.js commands

### Access Database
```bash
# MariaDB CLI
docker exec -it bp-mariadb-1 mariadb -uroot -proot

# Or connect from host
# Host: 127.0.0.1
# Port: 3306
# Username: root
# Password: root
# Database: bp
```

### Access Redis
```bash
docker exec -it bp-redis-1 redis-cli
```

## Ports

After starting containers, services are available at:

- **Backend API:** `http://localhost:8000` (via nginx)
- **Frontend Dev:** `http://localhost:3000` (pnpm dev)
- **Frontend Prod:** `http://localhost:3002` (Docker)
- **Database:** `127.0.0.1:3306`
- **Redis:** `127.0.0.1:6379`

## Environment Configuration

### Backend Environment

Edit `backend/.env`:
```env
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=bp
DB_USERNAME=root
DB_PASSWORD=root

APP_URL=http://localhost:8000
```

### Frontend Environment

Create `frontend/.env.local`:
```env
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
```

**Important:** For Docker containers, you may need to use the container name instead:
```env
# If frontend runs in Docker and backend is in Laradock
NEXT_PUBLIC_API_BASE_URL=http://nginx:80/api

# If frontend runs locally and backend is in Docker
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
```

## Troubleshooting

### Containers won't start
```bash
# Check if ports are already in use
netstat -ano | findstr :8000
netstat -ano | findstr :3306

# Check Docker logs
cd laradock
docker compose -p bp logs
```

### Database connection issues
```bash
# Verify database is running
docker ps | grep mariadb

# Check database logs
cd laradock
docker compose -p bp logs mariadb
```

### Frontend can't reach backend
1. Verify backend is running: `curl http://localhost:8000/api/up`
2. Check CORS configuration in `backend/config/cors.php`
3. Verify `NEXT_PUBLIC_API_BASE_URL` is set correctly
4. Check browser console for CORS errors

### Rebuild everything from scratch
```bash
# Stop and remove all containers
cd laradock
docker compose -p bp down -v --remove-orphans

# Rebuild and start
make init
```

## Quick Reference

| Task | Command |
|------|---------|
| First-time setup | `make init` |
| Start backend | `cd laradock && docker compose -p bp up -d` |
| Stop backend | `cd laradock && docker compose -p bp down` |
| Start frontend (dev) | `cd frontend && pnpm dev` |
| Start frontend (prod) | `cd frontend && docker compose -f docker-compose.prod.yml up -d` |
| View logs | `cd laradock && docker compose -p bp logs -f` |
| Access workspace | `docker exec -it bp-workspace-1 bash` |
| Run migrations | `docker exec -it bp-workspace-1 bash -c "cd /var/www && php artisan migrate"` |
