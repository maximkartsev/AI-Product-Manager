# ComfyUI Worker Agent

This worker polls the backend for jobs, runs ComfyUI locally, uploads outputs to S3, and reports status.

## Prerequisites

- Python 3.10+
- ComfyUI running locally (default: `http://localhost:8188`)

## Install

```
pip install -r requirements.txt
```

## Configuration (env vars)

- `API_BASE_URL` (e.g. `https://api.example.com`)
- `WORKER_ID` (unique ID for this worker)
- `WORKER_TOKEN` (must match `COMFYUI_WORKER_TOKEN`)
- `COMFYUI_BASE_URL` (default `http://localhost:8188`)
- `MAX_CONCURRENCY` (default `1`)
- `POLL_INTERVAL_SECONDS` (default `3`)
- `HEARTBEAT_INTERVAL_SECONDS` (default `30`)
- `CAPABILITIES` (JSON string, optional)

## Run

```
python comfyui_worker.py
```
