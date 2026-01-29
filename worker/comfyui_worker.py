import json
import mimetypes
import os
import tempfile
import time
import uuid
from typing import Any, Dict, Optional, Tuple, List
from urllib.parse import urlencode

import requests


API_BASE_URL = os.environ.get("API_BASE_URL", "http://localhost")
WORKER_ID = os.environ.get("WORKER_ID", f"worker-{uuid.uuid4()}")
WORKER_TOKEN = os.environ.get("WORKER_TOKEN", "")

COMFY_PROVIDER = os.environ.get("COMFY_PROVIDER", "local").lower()
COMFY_PROVIDERS = os.environ.get("COMFY_PROVIDERS", "")

COMFYUI_BASE_URL = os.environ.get("COMFYUI_BASE_URL", "http://localhost:8188")
COMFY_CLOUD_BASE_URL = os.environ.get("COMFY_CLOUD_BASE_URL", "https://cloud.comfy.org")
COMFY_CLOUD_API_KEY = os.environ.get("COMFY_CLOUD_API_KEY", "")
COMFY_MANAGED_API_KEY = os.environ.get("COMFY_MANAGED_API_KEY", "")

POLL_INTERVAL_SECONDS = int(os.environ.get("POLL_INTERVAL_SECONDS", "3"))
HEARTBEAT_INTERVAL_SECONDS = int(os.environ.get("HEARTBEAT_INTERVAL_SECONDS", "30"))
MAX_CONCURRENCY = int(os.environ.get("MAX_CONCURRENCY", "1"))
CAPABILITIES = os.environ.get("CAPABILITIES", "")


def _backend_headers() -> Dict[str, str]:
    headers = {"Content-Type": "application/json"}
    if WORKER_TOKEN:
        headers["Authorization"] = f"Bearer {WORKER_TOKEN}"
    return headers


def _backend_post(path: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    url = f"{API_BASE_URL}{path}"
    resp = requests.post(url, json=payload, headers=_backend_headers(), timeout=30)
    resp.raise_for_status()
    return resp.json()


def _cloud_headers() -> Dict[str, str]:
    if not COMFY_CLOUD_API_KEY:
        raise RuntimeError("COMFY_CLOUD_API_KEY is required for cloud provider.")
    return {"X-API-Key": COMFY_CLOUD_API_KEY}


def _cloud_request(method: str, path: str, **kwargs) -> requests.Response:
    url = f"{COMFY_CLOUD_BASE_URL}{path}"
    headers = kwargs.pop("headers", {})
    headers.update(_cloud_headers())
    resp = requests.request(method, url, headers=headers, timeout=60, **kwargs)
    resp.raise_for_status()
    return resp


def _parse_capabilities() -> Optional[Dict[str, Any]]:
    if not CAPABILITIES:
        return None
    try:
        return json.loads(CAPABILITIES)
    except json.JSONDecodeError:
        return {"raw": CAPABILITIES}


def _parse_providers() -> List[str]:
    if COMFY_PROVIDERS:
        return [provider.strip() for provider in COMFY_PROVIDERS.split(",") if provider.strip()]
    if COMFY_PROVIDER:
        return [COMFY_PROVIDER]
    return ["local"]


def poll(current_load: int) -> Optional[Dict[str, Any]]:
    payload = {
        "worker_id": WORKER_ID,
        "current_load": current_load,
        "max_concurrency": MAX_CONCURRENCY,
        "capabilities": _parse_capabilities(),
        "providers": _parse_providers(),
    }
    data = _backend_post("/api/worker/poll", payload)
    return data.get("data", {}).get("job")


def heartbeat(dispatch_id: int, lease_token: str) -> None:
    _backend_post("/api/worker/heartbeat", {
        "dispatch_id": dispatch_id,
        "lease_token": lease_token,
        "worker_id": WORKER_ID,
    })


def complete_job(dispatch_id: int, lease_token: str, provider_job_id: str, output_path: str) -> None:
    mime_type, _ = mimetypes.guess_type(output_path)
    payload = {
        "dispatch_id": dispatch_id,
        "lease_token": lease_token,
        "worker_id": WORKER_ID,
        "provider_job_id": provider_job_id,
        "output": {
            "size": os.path.getsize(output_path),
            "mime_type": mime_type or "video/mp4",
        },
    }
    _backend_post("/api/worker/complete", payload)


def fail_job(dispatch_id: int, lease_token: str, message: str) -> None:
    _backend_post("/api/worker/fail", {
        "dispatch_id": dispatch_id,
        "lease_token": lease_token,
        "worker_id": WORKER_ID,
        "error_message": message,
    })


def download_input(input_url: str) -> str:
    resp = requests.get(input_url, stream=True, timeout=60)
    resp.raise_for_status()
    suffix = os.path.splitext(input_url)[1] or ".bin"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        for chunk in resp.iter_content(chunk_size=1024 * 1024):
            if chunk:
                tmp.write(chunk)
        return tmp.name


def upload_output(output_url: str, output_headers: Dict[str, str], output_path: str) -> None:
    headers = output_headers or {}
    with open(output_path, "rb") as handle:
        resp = requests.put(output_url, data=handle, headers=headers, timeout=300)
        resp.raise_for_status()


def prepare_workflow(input_payload: Dict[str, Any], input_reference: Optional[str]) -> Dict[str, Any]:
    workflow = input_payload.get("workflow") or input_payload.get("comfyui_workflow")
    if not workflow:
        raise ValueError("Missing ComfyUI workflow in input_payload.")

    if input_reference:
        placeholder = input_payload.get("input_path_placeholder", "__INPUT_PATH__")
        serialized = json.dumps(workflow)
        if placeholder in serialized:
            workflow = json.loads(serialized.replace(placeholder, input_reference))

        input_node_id = input_payload.get("input_node_id")
        input_field = input_payload.get("input_field")
        if input_node_id is not None and input_field:
            workflow[str(input_node_id)]["inputs"][input_field] = input_reference

    return workflow


def run_comfyui(
    workflow: Dict[str, Any],
    output_node_id: Optional[str],
    extra_data: Optional[Dict[str, Any]] = None
) -> Tuple[str, Dict[str, Any]]:
    prompt_payload: Dict[str, Any] = {"prompt": workflow, "client_id": WORKER_ID}
    if extra_data:
        prompt_payload["extra_data"] = extra_data

    resp = requests.post(f"{COMFYUI_BASE_URL}/prompt", json=prompt_payload, timeout=30)
    resp.raise_for_status()
    prompt_id = resp.json().get("prompt_id")
    if not prompt_id:
        raise RuntimeError("ComfyUI did not return prompt_id.")

    start = time.time()
    while True:
        if time.time() - start > 3600:
            raise TimeoutError("ComfyUI job timed out.")

        history_resp = requests.get(f"{COMFYUI_BASE_URL}/history/{prompt_id}", timeout=15)
        history_resp.raise_for_status()
        history = history_resp.json()
        record = history.get(prompt_id) or history.get(str(prompt_id))

        if record:
            status = record.get("status", {})
            if status.get("status_str") == "error":
                raise RuntimeError(status.get("message", "ComfyUI error."))

            outputs = record.get("outputs", {})
            if outputs:
                return prompt_id, outputs

        time.sleep(2)


def extract_output_file(outputs: Dict[str, Any], output_node_id: Optional[str]) -> Dict[str, Any]:
    if output_node_id and str(output_node_id) in outputs:
        node_output = outputs[str(output_node_id)]
        for key in ("videos", "gifs", "images", "files", "video"):
            if key in node_output and node_output[key]:
                return node_output[key][0]

    for node_output in outputs.values():
        for key in ("videos", "gifs", "images", "files", "video"):
            if key in node_output and node_output[key]:
                return node_output[key][0]

    raise RuntimeError("No output file found in ComfyUI history.")


def download_comfyui_output(file_info: Dict[str, Any]) -> str:
    filename = file_info.get("filename")
    subfolder = file_info.get("subfolder", "")
    file_type = file_info.get("type", "output")

    if not filename:
        raise RuntimeError("ComfyUI output missing filename.")

    params = urlencode({
        "filename": filename,
        "subfolder": subfolder,
        "type": file_type,
    })
    url = f"{COMFYUI_BASE_URL}/view?{params}"
    resp = requests.get(url, stream=True, timeout=60)
    resp.raise_for_status()
    suffix = os.path.splitext(filename)[1] or ".bin"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        for chunk in resp.iter_content(chunk_size=1024 * 1024):
            if chunk:
                tmp.write(chunk)
        return tmp.name


def cloud_upload_asset_from_url(input_url: str, name: str, mime_type: Optional[str]) -> Dict[str, Any]:
    payload = {
        "url": input_url,
        "name": name,
        "tags": ["input"],
        "user_metadata": {
            "mime_type": mime_type or "application/octet-stream",
        },
    }
    resp = _cloud_request("POST", "/api/assets", json=payload)
    return resp.json()


def cloud_submit_prompt(workflow: Dict[str, Any], extra_data: Optional[Dict[str, Any]]) -> str:
    payload: Dict[str, Any] = {"prompt": workflow}
    if extra_data:
        payload["extra_data"] = extra_data

    resp = _cloud_request("POST", "/api/prompt", json=payload)
    data = resp.json()
    prompt_id = data.get("prompt_id")
    if not prompt_id:
        raise RuntimeError("Comfy Cloud did not return prompt_id.")
    return prompt_id


def cloud_wait_for_job(prompt_id: str) -> None:
    start = time.time()
    while True:
        if time.time() - start > 3600:
            raise TimeoutError("Comfy Cloud job timed out.")

        status_resp = _cloud_request("GET", f"/api/job/{prompt_id}/status")
        status_data = status_resp.json()
        status = status_data.get("status")
        if status in ("completed",):
            return
        if status in ("error", "cancelled"):
            raise RuntimeError(status_data.get("error_message") or "Comfy Cloud job failed.")

        time.sleep(2)


def cloud_fetch_outputs(prompt_id: str) -> Dict[str, Any]:
    history_resp = _cloud_request("GET", f"/api/history_v2/{prompt_id}")
    history = history_resp.json()
    record = history.get(prompt_id) or history.get(str(prompt_id))
    if not record:
        raise RuntimeError("Comfy Cloud history did not return outputs.")
    outputs = record.get("outputs", {})
    if not outputs:
        raise RuntimeError("Comfy Cloud outputs missing.")
    return outputs


def cloud_download_output(file_info: Dict[str, Any]) -> str:
    filename = file_info.get("filename")
    subfolder = file_info.get("subfolder", "")
    file_type = file_info.get("type", "output")

    if not filename:
        raise RuntimeError("Comfy Cloud output missing filename.")

    params = {
        "filename": filename,
        "subfolder": subfolder,
        "type": file_type,
    }
    resp = _cloud_request("GET", "/api/view", params=params, stream=True, allow_redirects=True)
    suffix = os.path.splitext(filename)[1] or ".bin"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        for chunk in resp.iter_content(chunk_size=1024 * 1024):
            if chunk:
                tmp.write(chunk)
        return tmp.name


def _safe_unlink(path: Optional[str]) -> None:
    if not path:
        return
    try:
        os.remove(path)
    except OSError:
        return


def process_job(job: Dict[str, Any]) -> None:
    dispatch_id = job["dispatch_id"]
    lease_token = job["lease_token"]
    input_url = job.get("input_url")
    output_url = job.get("output_url")
    output_headers = job.get("output_headers", {})
    input_payload = job.get("input_payload") or {}
    output_node_id = input_payload.get("output_node_id")

    if not output_url:
        raise RuntimeError("Missing output_url in job payload.")

    provider = (job.get("provider") or _parse_providers()[0]).lower()
    if provider not in _parse_providers():
        raise RuntimeError(f"Provider '{provider}' not supported by this worker.")

    input_reference = None
    input_path = None
    output_path = None

    try:
        if provider == "cloud":
            if not input_url:
                raise RuntimeError("Missing input_url for cloud provider.")

            asset_name = input_payload.get("input_name") or os.path.basename(input_url.split("?")[0])
            asset = cloud_upload_asset_from_url(input_url, asset_name, input_payload.get("input_mime_type"))
            input_reference = asset.get("asset_hash") or asset.get("name")
            if not input_reference:
                raise RuntimeError("Cloud asset reference missing.")

            workflow = prepare_workflow(input_payload, input_reference)
            prompt_id = cloud_submit_prompt(workflow, input_payload.get("extra_data"))
            cloud_wait_for_job(prompt_id)
            outputs = cloud_fetch_outputs(prompt_id)
            output_file_info = extract_output_file(outputs, output_node_id)
            output_path = cloud_download_output(output_file_info)

            upload_output(output_url, output_headers, output_path)
            complete_job(dispatch_id, lease_token, prompt_id, output_path)
            return

        input_path = download_input(input_url) if input_url else None
        workflow = prepare_workflow(input_payload, input_path)

        extra_data = input_payload.get("extra_data")
        if provider == "managed":
            if not COMFY_MANAGED_API_KEY:
                raise RuntimeError("COMFY_MANAGED_API_KEY is required for managed provider.")
            extra_data = dict(extra_data or {})
            extra_data.setdefault("api_key", COMFY_MANAGED_API_KEY)

        provider_job_id, outputs = run_comfyui(workflow, output_node_id, extra_data)
        output_file_info = extract_output_file(outputs, output_node_id)
        output_path = download_comfyui_output(output_file_info)

        upload_output(output_url, output_headers, output_path)
        complete_job(dispatch_id, lease_token, provider_job_id, output_path)
    finally:
        _safe_unlink(input_path)
        _safe_unlink(output_path)


def main() -> None:
    current_load = 0
    while True:
        try:
            job = poll(current_load)
            if not job:
                time.sleep(POLL_INTERVAL_SECONDS)
                continue

            current_load += 1
            last_heartbeat = time.time()

            try:
                while True:
                    now = time.time()
                    if now - last_heartbeat >= HEARTBEAT_INTERVAL_SECONDS:
                        heartbeat(job["dispatch_id"], job["lease_token"])
                        last_heartbeat = now
                    process_job(job)
                    break
            except Exception as exc:
                fail_job(job["dispatch_id"], job["lease_token"], str(exc))
            finally:
                current_load = max(0, current_load - 1)
        except Exception:
            time.sleep(POLL_INTERVAL_SECONDS)


if __name__ == "__main__":
    main()
