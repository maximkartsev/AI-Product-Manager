# ComfyUI Worker Agent (AWS Self-Hosted)

This worker polls the backend for jobs, runs ComfyUI on the same AWS node, uploads outputs to S3, and reports status.

## Runtime scope

- AWS self-hosted ComfyUI nodes (ASG fleet workers and AWS dev nodes).

## Prerequisites

- Python 3.10+
- ComfyUI available on the worker node (default: `http://localhost:8188`)

## Install

```bash
pip install -r requirements.txt
```

## Configuration (env vars)

- `API_BASE_URL` (e.g. `https://api.example.com`)
- `WORKER_ID` (AWS EC2 instance id, e.g. `i-...`)
- `WORKER_TOKEN` (issued by backend; optional when using fleet registration flow)
- `FLEET_SECRET` (required for auto registration via `/api/worker/register`)
- `FLEET_SLUG` (required for fleet registration)
- `FLEET_STAGE` (`staging` or `production`; optional, defaults backend-side)
- `COMFYUI_BASE_URL` (default `http://localhost:8188`)
- `MAX_CONCURRENCY` (default `1`)
- `POLL_INTERVAL_SECONDS` (default `3`)
- `HEARTBEAT_INTERVAL_SECONDS` (default `30`)
- `CAPABILITIES` (JSON string, optional)
- `ASG_NAME` (optional; enables scale-in protection toggling)

## Run

```bash
python comfyui_worker.py
```

Windows helper:

```bash
run_worker_aws.bat
```

## Stub worker (testing)

The stub worker downloads input and re-uploads it as output without calling ComfyUI.

```bash
python stub_worker.py
```
