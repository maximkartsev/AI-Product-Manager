# WSL workflow (Windows + Docker Desktop + WSL2)

This project can feel **very slow** if it lives on the Windows filesystem (`C:\...` / WSL path `/mnt/c/...`) while Docker runs in WSL2.  
For best performance, keep the repo **inside WSL** (Linux/ext4), and run Docker + dev commands from WSL.

---

## Prerequisites

### Windows
- Docker Desktop installed
- WSL2 enabled + an Ubuntu distro installed
- Docker Desktop → **Settings → Resources → WSL Integration** → enabled for your Ubuntu distro

### Inside WSL
Verify tools:

```bash
docker --version
docker compose version
git --version
make --version
pnpm --version   # only needed for frontend dev
python3 --version # only needed for worker
```

Install missing base tools:

```bash
sudo apt update
sudo apt install -y git make
```

Install Node + pnpm (if needed):

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install --lts
corepack enable
corepack prepare pnpm@latest --activate
pnpm -v
```

---

## 1) Put the repo in the WSL filesystem (recommended)

### Option A: Clone into WSL (cleanest)

```bash
mkdir -p ~/projects
cd ~/projects
git clone <REPO_URL>
cd AI-Product-Manager
git switch homework/4   # or your branch
```

### Option B: Copy your existing Windows folder into WSL (keeps local changes)

```bash
mkdir -p ~/projects
cp -r /mnt/c/Projects/AI-Product-Management/AI-Product-Manager ~/projects/
cd ~/projects/AI-Product-Manager
```

Confirm you are **not** on `/mnt/c/...`:

```bash
pwd
# should be /home/<you>/projects/AI-Product-Manager
```

---

## 2) Open the project in Cursor / IntelliJ (from WSL)

### Cursor
From WSL in the repo folder:

```bash
cursor .
```

If `cursor` isn’t found: in Cursor on Windows, run “Install `cursor` command in PATH”, then reopen WSL and retry.

**Tip:** avoid doing heavy operations via `\\wsl$...` (indexing, huge searches, running tooling). Prefer WSL/remote integration.

### IntelliJ IDEA
Best option is **Remote Development to WSL**:
- Install/open **JetBrains Gateway**
- Choose **WSL**
- Open folder: `/home/<you>/projects/AI-Product-Manager`

Fallback option (works, sometimes slower): open via Windows path:
`\\wsl$\<your-distro>\home\<you>\projects\AI-Product-Manager`

---

## 3) Backend (Laravel) — run only the needed Laradock services

### First-time setup (recommended)
From repo root:

```bash
make init
```

This initializes env files, builds containers, starts the minimal set, creates DBs, installs dependencies, runs migrations/seeds, etc.

### Start / stop (day-to-day)

```bash
cd laradock

# Start ONLY the services this project uses
docker compose -p bp up -d workspace php-fpm nginx mariadb redis

# Stop
docker compose -p bp down
```

**Important:** do **NOT** run `docker compose up -d` in `laradock/` without listing services — Laradock contains many optional services and it will try to start/pull everything.

### Useful backend commands

```bash
cd laradock

# See running containers
docker compose -p bp ps

# Workspace shell
docker compose -p bp exec workspace bash

# Migrate central + tenant pools
docker compose -p bp exec workspace bash -c "cd /var/www && php artisan tenancy:pools-migrate"
```

### Find the backend URL/port
Laradock maps nginx container port 80 to a host port configured in `laradock/.env`.
To see the actual port on your machine:

```bash
cd laradock
docker compose -p bp port nginx 80
```

Then use `http://localhost:<PORT>` in your browser.

---

## 4) Frontend (Next.js) — run in WSL

```bash
cd frontend
pnpm install
```

Create `frontend/.env.local` pointing to your backend:

```bash
echo "NEXT_PUBLIC_API_BASE_URL=http://localhost:<PORT>/api" > .env.local
```

Run dev server:

```bash
pnpm dev
```

If you intentionally keep the repo under `/mnt/c/...`, file watching can be unreliable/slow; in that case you may need polling:

```bash
WATCHPACK_POLLING=true pnpm exec next dev --webpack
```

---

## 5) Worker (optional, Python)
See `worker/README.md` for full details.

Typical setup in WSL:

```bash
cd worker
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Run (after setting required env vars like `API_BASE_URL`, `WORKER_TOKEN`, etc.):

```bash
python comfyui_worker.py
```

Stub worker (testing):

```bash
python stub_worker.py
```

---

## Troubleshooting

### “Compose tried to start Kafka/Mailu/Keycloak/etc…”
You ran `docker compose up -d` in `laradock/` without service names.

Fix:

```bash
cd laradock
docker compose -p bp down --remove-orphans
docker compose -p bp up -d workspace php-fpm nginx mariadb redis
```

### Still slow after moving to WSL?
- Ensure the repo path is under `/home/...` (not `/mnt/c/...`).
- Ensure Docker Desktop is using the WSL2 backend and WSL integration is enabled.
- Windows Defender / AV can slow file IO a lot; excluding the repo and WSL/Docker folders often helps.

