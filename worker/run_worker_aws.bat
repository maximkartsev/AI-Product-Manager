@echo off
setlocal

REM Run from this script's directory (worker)
set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%"

REM Create venv if missing
if not exist ".venv\Scripts\python.exe" (
  python -m venv .venv
)

REM Activate venv
call ".venv\Scripts\activate.bat"

REM Install deps (safe to re-run)
pip install -r requirements.txt

REM ---- AWS self-hosted worker configuration ----
set "API_BASE_URL=http://localhost:80"
set "FLEET_STAGE=staging"
set "WORKER_ID=i-replace-with-ec2-instance-id"
set "FLEET_SLUG=replace-with-fleet-slug"
set "FLEET_SECRET=replace-with-fleet-secret"
set "COMFYUI_BASE_URL=http://localhost:8188"

REM Optional: use a direct worker token instead of fleet registration.
REM set "WORKER_TOKEN=replace-with-issued-worker-token"

python ".\comfyui_worker.py"
