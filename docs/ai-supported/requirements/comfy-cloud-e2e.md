# Comfy Cloud E2E Video Test (MinIO + ngrok)

This guide configures MinIO as the S3 backend for Laravel and runs a real end-to-end video job on Comfy Cloud:
upload → process → store output back in MinIO → print download URL.

## Prerequisites
- Laradock services running: `nginx`, `php-fpm`, `workspace`, `mariadb`, `minio`
- Comfy Cloud subscription + API key
- ngrok installed (only required if MinIO is not publicly reachable)

## 1) Create a MinIO bucket
Open MinIO console: `http://localhost:9001`

Use credentials from `laradock/.env`:
- `MINIO_ROOT_USER`
- `MINIO_ROOT_PASSWORD`

Create a bucket (example): `bp-media`.

## 2) Configure Laravel to use MinIO (S3)
Set these in `backend/.env` (do not commit secrets):

```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<minio user>
AWS_SECRET_ACCESS_KEY=<minio password>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=bp-media
AWS_USE_PATH_STYLE_ENDPOINT=true
```

## 3) Expose MinIO with ngrok (required for Comfy Cloud)
Comfy Cloud must fetch the input video from a public URL, so your presigned URLs must be public.

Run ngrok:
```
ngrok http 9000
```

Copy the HTTPS forwarding URL (example: `https://xxxxx.ngrok-free.app`) and set:

```
AWS_ENDPOINT=https://xxxxx.ngrok-free.app
AWS_URL=https://xxxxx.ngrok-free.app
```

This ensures Laravel generates presigned URLs that are reachable from Comfy Cloud.

## 4) Prepare a Comfy Cloud workflow with a visible effect
In the Comfy Cloud UI (`https://cloud.comfy.org/`):

1. Create or load a **video** workflow that visibly changes the video.
2. Set the video input field to a placeholder like `__INPUT_PATH__`.
   - If Comfy Cloud requires asset references (e.g. `asset://`), you can wrap it:
     `asset://__INPUT_PATH__`
3. Identify the **output node id** that emits the final video.
4. Export the workflow JSON and save it to:
   - `backend/resources/comfyui/workflows/cloud_video_effect.json`

### Included sample: Neon rope / EL-wire
This repo includes a ready-made workflow:
- `backend/resources/comfyui/workflows/cloud_video_neon_rope.json`
- Output node id: `64`

Notes:
- The workflow expects `asset://__INPUT_PATH__` for the video input. If your workspace expects a raw asset hash, replace it with `__INPUT_PATH__`.
- It uses custom nodes from:
  - ComfyUI-VideoHelperSuite
  - ComfyUI-KJNodes
  - ComfyUI-post-processing-nodes

Relevant docs:
- Upload asset: https://docs.comfy.org/api-reference/cloud/asset/upload-a-new-asset
- Submit workflow: https://docs.comfy.org/api-reference/cloud/workflow/submit-a-workflow-for-execution
- Job status: https://docs.comfy.org/api-reference/cloud/job/get-job-status
- History (outputs): https://docs.comfy.org/api-reference/cloud/job/get-history-for-specific-prompt
- Download output: https://docs.comfy.org/api-reference/cloud/file/view-a-file

## 5) Run the Comfy Cloud worker
From the worker environment:

```
API_BASE_URL=http://localhost:80
WORKER_TOKEN=<COMFYUI_WORKER_TOKEN from backend/.env>
COMFY_PROVIDER=cloud
COMFY_CLOUD_API_KEY=<your Comfy Cloud key>
python worker/comfyui_worker.py
```

If running inside Laradock network, use:
```
API_BASE_URL=http://nginx
```

## 6) Run the E2E command
```
php artisan e2e:comfy-cloud-video \
  --input=/path/to/sample.mp4 \
  --workflow=backend/resources/comfyui/workflows/cloud_video_effect.json \
  --output-node-id=<node_id>
```

For the neon rope workflow:
```
php artisan e2e:comfy-cloud-video \
  --input=/path/to/sample.mp4 \
  --workflow=backend/resources/comfyui/workflows/cloud_video_neon_rope.json \
  --output-node-id=64
```

Optional:
- `--input-node-id=<node_id>` and `--input-field=<field>` if you need direct injection.
- `--api-base=http://nginx` when running inside Docker.

The command prints the MinIO download URL for the processed video.
