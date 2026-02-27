# Remote OAuth (Google/TikTok/Apple) — End-to-End Runbook

This repo’s OAuth flow is **frontend-callback based**: the backend builds provider redirect URLs using `FRONTEND_URL`, and providers redirect (or POST) back to the **frontend callback pages**, which then call the backend callback endpoints.

## Prerequisites (hard requirements)

- **HTTPS** for the remote host (Google/TikTok/Apple will not accept insecure callbacks).
- Deployed single-system stacks:
  - `bp-data`
  - `bp-compute`
- AWS CLI is configured and points to the correct account/region.

## Callback URLs you must register in provider consoles

Replace `<FRONTEND_URL>` with your deployed remote frontend base URL (same host as the app).

### Google
- `<FRONTEND_URL>/auth/google/signin/callback`
- `<FRONTEND_URL>/auth/google/signup/callback`

### TikTok
- `<FRONTEND_URL>/auth/tiktok/signin/callback`
- `<FRONTEND_URL>/auth/tiktok/signup/callback`

### Apple (callbacks are POST)
- `<FRONTEND_URL>/auth/apple/signin/callback`
- `<FRONTEND_URL>/auth/apple/signup/callback`

## Step 1 — Deploy infrastructure prerequisites (one-time)

From `infrastructure/`:

```powershell
npm ci
npx cdk deploy bp-data
```

This ensures these stores exist:
- Secrets Manager: `/bp/laravel/app-key`
- Secrets Manager: `/bp/oauth/secrets`
- SSM Parameter Store: `/bp/fleets/staging/fleet-secret`
- SSM Parameter Store: `/bp/fleets/production/fleet-secret`

## Step 2 — Prepare one app env file (input for sync script)

Use one env file (for example `backend/.env`) that contains:
- `APP_KEY` (Laravel key, `base64:...`)
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`
- `TIKTOK_CLIENT_ID`, `TIKTOK_CLIENT_SECRET`
- `APPLE_CLIENT_ID`, `APPLE_KEY_ID`, `APPLE_TEAM_ID`
- `COMFYUI_FLEET_SECRET_STAGING`, `COMFYUI_FLEET_SECRET_PRODUCTION` (optional; generated if missing)

Apple private key (`.p8`) is provided via script args (file paths), not via `.env`.

## Step 3 — Sync secrets from `.env` → AWS (SSM + Secrets Manager) and redeploy ECS

From `infrastructure/`:

```powershell
powershell -NoProfile -File .\scripts\sync-env-to-aws.ps1 `
  -Region us-east-1 `
  -EnvPath ..\backend\.env `
  -AppleP8Path C:\secure\keys\apple-production.p8
```

What this does:
- Writes SSM `/bp/fleets/staging/fleet-secret` (generates a value if missing in env file and prints a warning)
- Writes SSM `/bp/fleets/production/fleet-secret` (generates a value if missing in env file and prints a warning)
- Writes Secrets Manager `/bp/laravel/app-key`
- Writes Secrets Manager `/bp/oauth/secrets` as JSON (google/tiktok/apple fields + Apple `.p8` content)
- Forces ECS backend redeploy: `bp-backend`

## Step 4 — Deploy compute stack (ensures durable injection on future deploys)

From `infrastructure/`:

```powershell
npx cdk deploy bp-compute
```

This applies the durable ECS wiring:
- Injects OAuth env vars into backend PHP containers (`php-fpm`, `scheduler`, `queue-worker`) from `/bp/oauth/secrets`
- Sets `FRONTEND_URL` to the deployed host base URL
- Bootstraps Apple key file inside the container at `/var/www/html/storage/keys/Apple_AuthKey.p8` before running php-fpm / artisan processes

## Step 5 — Remote smoke test

Replace `<HOST>` with your deployed host (same as `FRONTEND_URL` host).

- `GET https://<HOST>/api/auth/google/signin`
- `GET https://<HOST>/api/auth/tiktok/signin`
- `GET https://<HOST>/api/auth/apple/signin`

Expected: a JSON response containing a provider redirect URL (not 500/503).

## Common failure modes (quick checks)

- **HTTP-only host**: providers reject callbacks → fix HTTPS first.
- **Wrong callback URLs registered**: provider redirects to a different URL than the one used by the app → register the exact callback URLs listed above.
- **Missing secrets in `/bp/oauth/secrets`**: backend returns 500 on redirect endpoints → re-run the sync script, then redeploy compute.
- **Apple key not written**: Apple flow fails during token exchange → ensure `apple_private_key_p8_b64` exists in `/bp/oauth/secrets` and ECS tasks have restarted.

