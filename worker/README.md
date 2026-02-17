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
- `COMFY_PROVIDER` (`local`, `managed`, or `cloud`; default `local`)
- `COMFY_PROVIDERS` (comma-separated list if the worker supports multiple providers)
- `COMFYUI_BASE_URL` (default `http://localhost:8188`, used for `local`/`managed`)
- `COMFY_MANAGED_API_KEY` (required for `managed` when workflows use paid API nodes)
- `COMFY_CLOUD_BASE_URL` (default `https://cloud.comfy.org`)
- `COMFY_CLOUD_API_KEY` (required for `cloud`, sent as `X-API-Key`)
- `MAX_CONCURRENCY` (default `1`)
- `POLL_INTERVAL_SECONDS` (default `3`)
- `HEARTBEAT_INTERVAL_SECONDS` (default `30`)
- `CAPABILITIES` (JSON string, optional)

## Comfy Cloud + MinIO E2E
For the full end-to-end flow (upload → cloud → MinIO), see:
`docs/ai-supported/requirements/comfy-cloud-e2e.md`.

Required env vars for cloud mode:
- `COMFY_PROVIDER=cloud`
- `COMFY_CLOUD_API_KEY=<your Comfy Cloud key>`
- `WORKER_TOKEN=<COMFYUI_WORKER_TOKEN>`
- `API_BASE_URL=http://localhost:80` (or `http://nginx` in Docker)

## Run

```
python comfyui_worker.py
```

## Stub worker (testing)
The stub worker downloads the input and re-uploads it as the output, marking the job complete without calling ComfyUI.

```
python stub_worker.py

OR

run_stub_worker.bat
```
