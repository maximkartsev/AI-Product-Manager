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

REM ---- Stub configuration ----
REM Backend API (host machine; docker exposes backend on 80)
set "API_BASE_URL=http://localhost:80"

REM Must match backend COMFYUI_WORKER_TOKEN
set "WORKER_TOKEN=local-worker-token"

REM Optional: set a stable worker id
set "WORKER_ID=stub-worker-%COMPUTERNAME%"

python ".\stub_worker.py"
