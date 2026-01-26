# Project Setup (Windows + WSL2)

This project is intended to be run on **Windows using WSL2 (Ubuntu)** and **Docker Desktop**.

All commands below are executed **inside Ubuntu (WSL)** unless explicitly stated otherwise.

---

## 1. System Requirements

### Windows

* Docker Desktop installed and running
* WSL2 enabled
* Ubuntu installed via WSL

### Docker Desktop

* WSL2 backend enabled
* WSL integration enabled for Ubuntu


#### Enable integration

* Open **Docker Desktop**
* Go to **Settings → Resources → WSL Integration**
* Enable integration for your Ubuntu distro
* Apply & restart Docker Desktop


---

## 2. Open Ubuntu (WSL)

Start **Ubuntu** from the Windows Start Menu.

---

## 3. Required Tools (WSL)

Verify the following tools are available:

```bash
docker --version
docker compose version
git --version
make --version
pnpm --version
```

### Install missing base tools

```bash
sudo apt update
sudo apt install -y make git
```

### Install Node.js and pnpm (if pnpm is missing)

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install --lts
corepack enable
corepack prepare pnpm@latest --activate
pnpm -v
```

---

## 4. Project Location

The project is located on the Windows filesystem and accessed via WSL.

Example Windows path:

```
C:\Projects\AI_Product_Management\AI-Product-Manager
```

Navigate to the project in WSL:

```bash
cd /mnt/c/Projects/AI_Product_Management/AI-Product-Manager
```

Verify structure:

```bash
ls
```

Expected directories:

```
Makefile
backend/
frontend/
laradock/
```

---

## 5. Initialize the Project

From the project root:

```bash
make init
```

This command performs:

* Git submodule initialization (Laradock)
* Environment file setup
* Docker image build
* Container startup
* Database creation
* Backend dependency installation
* Database migrations
* Frontend dependency installation

---

## 5.1 AI OS workflow checks (recommended before “release”)

After you’ve made changes, use the local enforcement gates:

```bash
make preflight
make release-check
```

Notes:

- `make preflight` runs docs checks, backend migrations/tests, and frontend lint/build.
- `make release-check` validates that the latest CycleRecord in `docs/ai-supported/cycles/` is complete and marked `status: ready` (or `released`).

---

## 6. Container Management

### Stop containers

```bash
cd laradock
docker compose -p bp down
```

### Start containers

```bash
cd laradock
docker compose -p bp up -d
```

### Recreate containers

```bash
cd laradock
docker compose -p bp down -v --remove-orphans
docker compose -p bp up -d
```

---

## 7. Access Containers

### Workspace container

```bash
docker exec -it bp-workspace-1 bash
```

### MariaDB

```bash
docker exec -it bp-mariadb-1 mariadb -uroot -proot
```

---

## 8. Database Configuration

### Application (Docker network)

```env
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=bp
DB_USERNAME=root
DB_PASSWORD=root
```

### Host access (DB client)

* Host: `127.0.0.1`
* Port: `3306`
* Username: `root`
* Password: `root`
* Database: `bp`

---

## 9. Laradock Configuration

The backend code must be mounted correctly.

In `laradock/.env`:

```env
APP_CODE_PATH_HOST=../backend
```

After changing this value, containers must be recreated:

```bash
cd laradock
docker compose -p bp down
docker compose -p bp up -d
```

---

## 10. Running the Application

After successful initialization:

* Backend is available via nginx container
* Frontend is available via the configured dev server (pnpm)

Ports are defined in `laradock/docker-compose.yml`.

---

## 11. Supported Usage

* Commands are executed in **WSL**
* Docker is managed via **Docker Desktop**
* Project lifecycle is controlled via **Makefile**

---

## 12. Quick Start

```bash
cd /mnt/c/Projects/AI_Product_Management/AI-Product-Manager
make init
```