# Comfy Cloud E2E Troubleshooting (Full Chat Digest)

This document summarizes the **working command execution patterns**, **Comfy Cloud/ComfyUI specifics**, and **all major issues that required multiple research/approach changes** across the full conversation (previous summary + current session). All sensitive values are redacted.

## 1) Working command execution patterns

### Docker + Laravel (E2E)
- Run docker commands from the `laradock/` folder so service names resolve correctly.
- Use the `workspace` container for `php artisan`:
```
docker compose -p bp exec workspace php artisan e2e:comfy-cloud-video \
  --input="/var/www/resources/IMG_6140.mp4" \
  --workflow="/var/www/resources/comfyui/workflows/cloud_video_neon_rope.json" \
  --output-node-id=64 \
  --api-base="http://nginx"
```
- **Important:** `--api-base="http://nginx"` avoids localhost networking errors inside Docker.

### Worker (PowerShell)
Start the Comfy Cloud worker on the host (Python available):
```
powershell -Command "
  `$env:API_BASE_URL='http://localhost:80';
  `$env:WORKER_TOKEN='<WORKER_TOKEN>';
  `$env:COMFY_PROVIDER='cloud';
  `$env:COMFY_CLOUD_API_KEY='<COMFY_CLOUD_API_KEY>';
  python 'worker/comfyui_worker.py'
"
```

### PowerShell quoting that worked
- Use **single quotes inside a double-quoted PowerShell command** to avoid `$` interpolation issues.
- If you must keep `$` in a double-quoted string, escape it with a backtick.
- Avoid heredoc in PowerShell for `php -r` (caused `Missing file specification after redirection operator`).

Example pattern (Docker + `php -r`):
```
docker compose -p bp exec workspace php -r "`$path = '/var/www/.env'; ...;"
```

### Waiting for background work
```
powershell -Command "Start-Sleep -Seconds 30"
```

### Converting MOV to MP4 for Comfy Cloud
```
ffmpeg -y -i "backend/resources/IMG_6140.MOV" \
  -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p \
  -c:a aac -b:a 128k "backend/resources/IMG_6140.mp4"
```

## 2) Comfy Cloud / ComfyUI specifics

### Prompt format & placeholders
- Comfy Cloud expects **ComfyUI API “prompt” JSON**.
- Use a placeholder like `__INPUT_PATH__` in the workflow and replace it in the worker.
- Output node ID must be passed to the E2E command (`--output-node-id=64`).

### Input handling (critical)
`VHS_LoadVideo` in Comfy Cloud **expects a filename from `/api/upload/image`**, not:
- `asset://<asset_id>`
- `asset://<asset_hash>`
- a direct URL

Correct input flow:
1. Upload video to Comfy Cloud via `/api/upload/image` (multipart).
2. Use the **returned filename** as the value for `video` in `VHS_LoadVideo`.

### Output formats & node validation
`VHS_VideoCombine`:
- Valid format: `video/h264-mp4`
- `loop_count` must be an integer (e.g., `0`)

### Comfy Cloud job statuses
Observed statuses:
`preparing`, `executing`, `success`, `completed`, `non_retryable_error`, `retryable_error`

The worker must treat:
- `success` and `completed` as terminal success
- `non_retryable_error` and `retryable_error` as terminal failures

### Key endpoints used
- `POST /api/prompt`
- `GET /api/job/{prompt_id}/status`
- `GET /api/history_v2/{prompt_id}`
- `GET /api/view`
- `POST /api/upload/image` (critical for video inputs)

## 3) Do / Don’t list

### Do
- Do run Docker commands in `laradock/` and use `workspace`.
- Do ensure input video is inside repo, mounted as `/var/www/resources/...`.
- Do use `--api-base="http://nginx"` inside Docker.
- Do use `/api/upload/image` for video inputs to `VHS_LoadVideo`.
- Do use `video/h264-mp4` + `loop_count=0` in `VHS_VideoCombine`.
- Do use signed output URLs (`Storage::temporaryUrl`) for MinIO/S3 downloads.
- Do redact secrets in logs/docs.

### Don’t
- Don’t use host paths like `C:\Users\...` inside container commands.
- Don’t assume MinIO credentials (`minioadmin`) without verification.
- Don’t use unsupported nodes in Comfy Cloud (e.g., `Glow`).
- Don’t use `asset://...` for `VHS_LoadVideo` (causes `ImageDownloadError`).
- Don’t expect direct file URLs to be public if MinIO is private.

## 4) Issues that required multiple research/approach changes

Each item below had **at least two research/approach changes** before the final fix.

### A) Input file not found in container
**Symptom:** `Input file not found: C:\Users\...` and `/host_mnt/...` paths failed.  
**Root cause:** Docker container only sees mounted project files.  
**Failed attempts:** Host paths, `/host_mnt/`, `/mnt/c/`, `/c/`.  
**Final fix:** Move input video to repo and use `/var/www/resources/IMG_6140.MOV`.  
**Approach change:** Stop trying host paths, align with Docker volume mapping.

### B) Upload URL generation failed / localhost errors
**Symptom:** `cURL error 7 ... http://localhost/api/register`.  
**Root cause:** API base set to localhost from inside container.  
**Failed attempts:** Default `APP_URL` and host URLs.  
**Final fix:** `--api-base="http://nginx"` for internal Docker networking.  
**Approach change:** Treat container networking as first-class; stop using host.

### C) MinIO credentials + S3 config mismatch
**Symptom:** `Upload URL generation failed` and `InvalidAccessKeyId`.  
**Root cause:** Wrong credentials and missing `FILESYSTEM_DISK=s3`.  
**Failed attempts:** `minioadmin:minioadmin`, partial env changes.  
**Final fix:** Update container `.env` to `laradock/laradock` + full S3 config.  
**Approach change:** Read docker-compose defaults, update `.env` inside container.

### D) Comfy Cloud cannot fetch MinIO URLs
**Symptom:** `400 Client Error ... /api/assets` from Comfy Cloud.  
**Root cause:** Presigned URLs pointed to internal `http://minio:9000`.  
**Failed attempts:** Internal URL, no public endpoint.  
**Final fix:** Use public HTTPS MinIO endpoint via ngrok (`<NGROK_URL>`).  
**Approach change:** Move from internal Docker URLs to public endpoints.

### E) Cloud input assets repeatedly failing (`ImageDownloadError`)
**Symptom:** Comfy Cloud jobs fail with `ImageDownloadError`.  
**Root cause:** `VHS_LoadVideo` does **not** accept `asset://` or direct URLs.  
**Failed attempts:**
- `POST /api/assets` with URL
- `asset://<asset_id>` and `asset://<asset_hash>`
- direct URL in `video` field  
**Final fix:** `POST /api/upload/image` and use returned **filename** in workflow.  
**Approach change:** Shift from asset references to Comfy Cloud “input file” upload.

### F) Workflow node validation failures
**Symptom:** `Invalid workflow: unsupported type 'Glow'` and `format yuv420p not in list`, `loop_count` invalid.  
**Root cause:** Comfy Cloud node set is stricter and doesn’t include `Glow`; output node expects valid format list + integers.  
**Failed attempts:** `Glow` node, `yuv420p` format, null loop_count.  
**Final fix:** Replace `Glow` with `ImageBlur`; set `format="video/h264-mp4"` and `loop_count=0`.  
**Approach change:** Validate with `/api/prompt` and adjust nodes to cloud-available set.

### G) Worker reliability fixes (multiple iterations)
**Symptoms:**
- Invalid temp filename due to URL query string
- Double `timeout` arg crash
- Job stuck in `processing` forever
- Output upload failing due to header type list  
**Root causes:** URL parsing, duplicate timeout arg, unhandled statuses, header normalization.  
**Failed attempts:** Initial worker logic without fixes.  
**Final fixes:**
- Strip query string for suffix
- Handle `timeout` in `_cloud_request`
- Treat `success` as completion and error statuses as terminal
- Normalize header lists to strings  
**Approach change:** Inspect failures via logs and Comfy Cloud job status API.

### H) Output download AccessDenied
**Symptom:** `AccessDenied` when downloading output.  
**Root cause:** Output URL not signed / private MinIO bucket.  
**Failed attempts:** Use raw URL from file record.  
**Final fix:** Use `Storage::disk(...)->temporaryUrl()` and save output locally.  
**Approach change:** Switch from direct URL to signed URL generation.

---

## 5) Final known-good flow (redacted)
```
docker compose -p bp exec workspace php artisan e2e:comfy-cloud-video \
  --input="/var/www/resources/IMG_6140.mp4" \
  --workflow="/var/www/resources/comfyui/workflows/cloud_video_neon_rope.json" \
  --output-node-id=64 \
  --api-base="http://nginx"
```

Worker:
```
powershell -Command "
  `$env:API_BASE_URL='http://localhost:80';
  `$env:WORKER_TOKEN='<WORKER_TOKEN>';
  `$env:COMFY_PROVIDER='cloud';
  `$env:COMFY_CLOUD_API_KEY='<COMFY_CLOUD_API_KEY>';
  python 'worker/comfyui_worker.py'
"
```

Output saved to:
`backend/resources/comfyui/output/`
