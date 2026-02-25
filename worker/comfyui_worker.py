import hashlib
import json
import mimetypes
import os
import signal
import tempfile
import threading
import time
import uuid
from typing import Any, Dict, Optional, Tuple, List
from urllib.parse import urlencode

import requests


API_BASE_URL = os.environ.get("API_BASE_URL", "http://localhost")
WORKER_ID = os.environ.get("WORKER_ID", f"worker-{uuid.uuid4()}")
WORKER_TOKEN = os.environ.get("WORKER_TOKEN", "")
FLEET_SECRET = os.environ.get("FLEET_SECRET", "")

COMFY_PROVIDER = os.environ.get("COMFY_PROVIDER", "self_hosted").lower()
COMFY_PROVIDERS = os.environ.get("COMFY_PROVIDERS", "")

COMFYUI_BASE_URL = os.environ.get("COMFYUI_BASE_URL", "http://localhost:8188")
COMFY_CLOUD_BASE_URL = os.environ.get("COMFY_CLOUD_BASE_URL", "https://cloud.comfy.org")
COMFY_CLOUD_API_KEY = os.environ.get("COMFY_CLOUD_API_KEY", "")

POLL_INTERVAL_SECONDS = int(os.environ.get("POLL_INTERVAL_SECONDS", "3"))
HEARTBEAT_INTERVAL_SECONDS = int(os.environ.get("HEARTBEAT_INTERVAL_SECONDS", "30"))
MAX_CONCURRENCY = int(os.environ.get("MAX_CONCURRENCY", "1"))
CAPABILITIES = os.environ.get("CAPABILITIES", "")

# ASG / Spot instance support
ASG_NAME = os.environ.get("ASG_NAME", "")
FLEET_SLUG = os.environ.get("FLEET_SLUG", "")
FLEET_STAGE = os.environ.get("FLEET_STAGE", "")

# Shutdown state
_shutdown_requested = False
_shutdown_reason = ""
_current_job: Optional[Dict[str, Any]] = None

# Asset upload cache: (endpoint, content_hash) → comfyui_filename
_asset_cache: Dict[Tuple[str, str], str] = {}


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
    timeout = kwargs.pop("timeout", 60)
    resp = requests.request(method, url, headers=headers, timeout=timeout, **kwargs)
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
    return ["self_hosted"]


def _fetch_imds(path: str) -> Optional[str]:
    try:
        tok = requests.put(
            "http://169.254.169.254/latest/api/token",
            headers={"X-aws-ec2-metadata-token-ttl-seconds": "30"},
            timeout=1,
        ).text
        resp = requests.get(
            f"http://169.254.169.254/latest/{path}",
            headers={"X-aws-ec2-metadata-token": tok},
            timeout=1,
        )
        if resp.status_code != 200:
            return None
        return resp.text
    except Exception:
        return None


def _detect_capacity_type() -> Optional[str]:
    lifecycle = _fetch_imds("meta-data/instance-life-cycle")
    if lifecycle and lifecycle.strip().lower() == "spot":
        return "spot"
    return "on-demand"


def _detect_instance_type() -> Optional[str]:
    instance_type = _fetch_imds("meta-data/instance-type")
    return instance_type.strip() if instance_type else None


def _check_spot_interruption() -> bool:
    """Check EC2 instance metadata for Spot interruption notice (2-min warning)."""
    return _fetch_imds("meta-data/spot/instance-action") is not None


def _check_spot_rebalance() -> bool:
    """Check EC2 instance metadata for Spot rebalance recommendation."""
    return _fetch_imds("meta-data/events/recommendations/rebalance") is not None


def _check_asg_termination() -> bool:
    """Check ASG target lifecycle state (scale-in/termination intent)."""
    state = _fetch_imds("meta-data/autoscaling/target-lifecycle-state")
    if not state:
        return False
    return state.strip() not in ("InService", "")


def _termination_monitor() -> None:
    """Background thread polling for termination/rebalance signals every 5 seconds."""
    global _shutdown_requested, _shutdown_reason
    while not _shutdown_requested:
        if _check_spot_interruption():
            _shutdown_requested = True
            _shutdown_reason = "spot_interruption"
            print("[worker] Spot interruption notice received!")
            break
        if _check_spot_rebalance():
            _shutdown_requested = True
            _shutdown_reason = "spot_rebalance"
            print("[worker] Spot rebalance recommendation received!")
            break
        if _check_asg_termination():
            _shutdown_requested = True
            _shutdown_reason = "asg_termination"
            print("[worker] ASG termination intent detected!")
            break
        time.sleep(5)


def _requeue_job(dispatch_id: int, lease_token: str, reason: str) -> None:
    """Ask backend to requeue job (don't count as failed attempt)."""
    try:
        requests.post(
            f"{API_BASE_URL}/api/worker/requeue",
            json={"dispatch_id": dispatch_id, "lease_token": lease_token, "reason": reason},
            headers=_backend_headers(),
            timeout=10,
        )
    except Exception as e:
        print(f"[worker] Requeue failed: {e}")


def _set_scale_in_protection(protected: bool) -> None:
    """Set ASG scale-in protection for this instance."""
    if not ASG_NAME:
        return
    try:
        import boto3
        client = boto3.client("autoscaling")
        client.set_instance_protection(
            InstanceIds=[WORKER_ID],
            AutoScalingGroupName=ASG_NAME,
            ProtectedFromScaleIn=protected,
        )
    except Exception as e:
        print(f"[worker] Scale-in protection error: {e}")


def _fleet_register() -> Tuple[str, str]:
    """Register this worker with the backend via fleet secret. Returns (worker_id, token)."""
    if not FLEET_SLUG:
        raise RuntimeError("FLEET_SLUG is required for fleet registration.")
    payload: Dict[str, Any] = {
        "worker_id": WORKER_ID,
        "display_name": WORKER_ID,
        "capabilities": _parse_capabilities(),
        "max_concurrency": MAX_CONCURRENCY,
        "fleet_slug": FLEET_SLUG,
    }
    if FLEET_STAGE:
        payload["stage"] = FLEET_STAGE
    capacity_type = _detect_capacity_type()
    if capacity_type:
        payload["capacity_type"] = capacity_type
    instance_type = _detect_instance_type()
    if instance_type:
        payload["instance_type"] = instance_type

    resp = requests.post(
        f"{API_BASE_URL}/api/worker/register",
        json=payload,
        headers={"Content-Type": "application/json", "X-Fleet-Secret": FLEET_SECRET},
        timeout=30,
    )
    resp.raise_for_status()
    data = resp.json().get("data", {})
    return data["worker_id"], data["token"]


def _fleet_deregister(reason: Optional[str] = None) -> None:
    """Deregister this worker from the backend."""
    try:
        payload = {"reason": reason} if reason else {}
        requests.post(
            f"{API_BASE_URL}/api/worker/deregister",
            json=payload,
            headers=_backend_headers(),
            timeout=10,
        )
    except Exception as e:
        print(f"[worker] Deregister failed: {e}")


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
    url_path = input_url.split("?", 1)[0]
    suffix = os.path.splitext(url_path)[1] or ".bin"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        for chunk in resp.iter_content(chunk_size=1024 * 1024):
            if chunk:
                tmp.write(chunk)
        return tmp.name


def upload_output(output_url: str, output_headers: Dict[str, str], output_path: str) -> None:
    headers = output_headers or {}
    normalized = {}
    for key, value in headers.items():
        if isinstance(value, list):
            if value:
                normalized[key] = value[0]
            continue
        normalized[key] = value
    with open(output_path, "rb") as handle:
        resp = requests.put(output_url, data=handle, headers=normalized, timeout=300)
        resp.raise_for_status()


def upload_to_comfyui(file_path: str, endpoint: str) -> str:
    """Upload a file to local ComfyUI via POST /upload/image."""
    url = f"{endpoint}/upload/image"
    file_name = os.path.basename(file_path)
    mime_type = mimetypes.guess_type(file_path)[0] or "application/octet-stream"
    with open(file_path, "rb") as handle:
        files = {"image": (file_name, handle, mime_type)}
        resp = requests.post(url, files=files, data={"type": "input", "overwrite": "true"}, timeout=300)
        resp.raise_for_status()
        result = resp.json()
        name = result.get("name")
        if not name:
            raise RuntimeError("ComfyUI upload did not return a filename.")
        return name


def download_and_upload_assets(
    assets: List[Dict[str, Any]],
    provider: str,
    endpoint: str,
) -> Dict[str, str]:
    """Download assets from presigned URLs and upload to ComfyUI.

    Returns a mapping of placeholder → comfyui_filename.
    """
    placeholder_map: Dict[str, str] = {}
    for asset in assets:
        placeholder = asset.get("placeholder")
        download_url = asset.get("download_url")
        content_hash = asset.get("content_hash")
        is_primary = asset.get("is_primary_input", False)

        if not placeholder or not download_url:
            continue

        # Check cache for non-primary assets with a content hash
        cache_key = (endpoint, content_hash) if content_hash and not is_primary else None
        if cache_key and cache_key in _asset_cache:
            placeholder_map[placeholder] = _asset_cache[cache_key]
            continue

        # Download the asset
        tmp_path = download_input(download_url)
        try:
            # Upload to ComfyUI
            if provider == "cloud":
                asset_name = os.path.basename(download_url.split("?")[0])
                upload_result = cloud_upload_input_file(tmp_path, asset_name, None)
                comfyui_name = upload_result.get("name", asset_name)
            else:
                comfyui_name = upload_to_comfyui(tmp_path, endpoint)

            placeholder_map[placeholder] = comfyui_name

            # Cache for future use
            if cache_key:
                _asset_cache[cache_key] = comfyui_name
        finally:
            _safe_unlink(tmp_path)

    return placeholder_map


def prepare_workflow(input_payload: Dict[str, Any], input_reference: Optional[str], placeholder_map: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    workflow = input_payload.get("workflow") or input_payload.get("comfyui_workflow")
    if not workflow:
        raise ValueError("Missing ComfyUI workflow in input_payload.")

    # Apply placeholder_map replacements (from asset pipeline)
    if placeholder_map:
        serialized = json.dumps(workflow)
        for placeholder, filename in placeholder_map.items():
            serialized = serialized.replace(placeholder, filename)
        workflow = json.loads(serialized)

    if input_reference:
        placeholder = input_payload.get("input_path_placeholder", "__INPUT_PATH__")
        reference_prefix = input_payload.get("input_reference_prefix")
        is_asset_reference = reference_prefix is None and not os.path.exists(input_reference)
        serialized = json.dumps(workflow)
        if placeholder in serialized:
            if reference_prefix is not None:
                if reference_prefix:
                    serialized = serialized.replace(
                        f"{reference_prefix}{placeholder}",
                        f"{reference_prefix}{input_reference}",
                    )
                    serialized = serialized.replace(
                        placeholder, f"{reference_prefix}{input_reference}"
                    )
                else:
                    serialized = serialized.replace(
                        f"asset://{placeholder}", input_reference
                    )
                    serialized = serialized.replace(placeholder, input_reference)
            elif is_asset_reference:
                serialized = serialized.replace(
                    f"asset://{placeholder}", f"asset://{input_reference}"
                )
                serialized = serialized.replace(placeholder, f"asset://{input_reference}")
            else:
                serialized = serialized.replace(placeholder, input_reference)
            workflow = json.loads(serialized)

        input_node_id = input_payload.get("input_node_id")
        input_field = input_payload.get("input_field")
        if input_node_id is not None and input_field:
            value = input_reference
            if reference_prefix and not str(value).startswith(reference_prefix):
                value = f"{reference_prefix}{value}"
            elif is_asset_reference and not str(value).startswith("asset://"):
                value = f"asset://{value}"
            workflow[str(input_node_id)]["inputs"][input_field] = value

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


def cloud_upload_input_file(file_path: str, name: str, mime_type: Optional[str]) -> Dict[str, Any]:
    file_name = name or os.path.basename(file_path)
    file_mime = mime_type or "application/octet-stream"
    form_data = {
        "type": "input",
    }
    with open(file_path, "rb") as handle:
        files = {"image": (file_name, handle, file_mime)}
        resp = _cloud_request("POST", "/api/upload/image", data=form_data, files=files, timeout=300)
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
        if status in ("completed", "success"):
            return
        if status in ("error", "cancelled", "failed", "non_retryable_error", "retryable_error"):
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

    # Handle asset pipeline if assets are present in input_payload
    assets = input_payload.get("assets")
    asset_placeholder_map: Optional[Dict[str, str]] = None

    try:
        if assets and isinstance(assets, list):
            comfyui_endpoint = COMFY_CLOUD_BASE_URL if provider == "cloud" else COMFYUI_BASE_URL
            asset_placeholder_map = download_and_upload_assets(assets, provider, comfyui_endpoint)

        if provider == "cloud":
            if not input_url and not asset_placeholder_map:
                raise RuntimeError("Missing input_url for cloud provider.")

            if input_url and not asset_placeholder_map:
                # Legacy path: no asset pipeline
                asset_name = input_payload.get("input_name") or os.path.basename(input_url.split("?")[0])
                input_path = download_input(input_url)
                upload = cloud_upload_input_file(input_path, asset_name, input_payload.get("input_mime_type"))
                input_reference = upload.get("name")
                if not input_reference:
                    raise RuntimeError("Cloud input upload missing filename.")
                input_payload["input_reference_prefix"] = ""
            elif input_url:
                # Asset pipeline handled the primary input upload already
                input_payload["input_reference_prefix"] = ""

            workflow = prepare_workflow(input_payload, input_reference, asset_placeholder_map)
            extra_data = dict(input_payload.get("extra_data") or {})
            prompt_id = cloud_submit_prompt(workflow, extra_data or None)
            cloud_wait_for_job(prompt_id)
            outputs = cloud_fetch_outputs(prompt_id)
            output_file_info = extract_output_file(outputs, output_node_id)
            output_path = cloud_download_output(output_file_info)

            upload_output(output_url, output_headers, output_path)
            complete_job(dispatch_id, lease_token, prompt_id, output_path)
            return

        # self_hosted: run on local ComfyUI instance
        input_path = download_input(input_url) if input_url else None
        workflow = prepare_workflow(input_payload, input_path, asset_placeholder_map)

        extra_data = input_payload.get("extra_data")
        provider_job_id, outputs = run_comfyui(workflow, output_node_id, extra_data)
        output_file_info = extract_output_file(outputs, output_node_id)
        output_path = download_comfyui_output(output_file_info)

        upload_output(output_url, output_headers, output_path)
        complete_job(dispatch_id, lease_token, provider_job_id, output_path)
    finally:
        _safe_unlink(input_path)
        _safe_unlink(output_path)


def main() -> None:
    global WORKER_ID, WORKER_TOKEN, _shutdown_requested, _shutdown_reason, _current_job

    # SIGTERM handler for graceful shutdown
    def _handle_sigterm(signum, frame):
        global _shutdown_requested, _shutdown_reason
        _shutdown_requested = True
        if not _shutdown_reason:
            _shutdown_reason = "sigterm"
        print(f"[worker] Received SIGTERM, shutting down...")

    signal.signal(signal.SIGTERM, _handle_sigterm)

    # Fleet self-registration (ASG workers)
    if FLEET_SECRET and not WORKER_TOKEN:
        print(f"[worker] Fleet registration as {WORKER_ID}...")
        WORKER_ID, WORKER_TOKEN = _fleet_register()
        print(f"[worker] Registered as {WORKER_ID}")

    print(f"[worker] Starting as {WORKER_ID}")

    # Start Spot interruption monitor for ASG instances
    if ASG_NAME:
        threading.Thread(target=_termination_monitor, daemon=True).start()

    current_load = 0
    while not _shutdown_requested:
        try:
            _set_scale_in_protection(False)
            job = poll(current_load)
            if not job:
                time.sleep(POLL_INTERVAL_SECONDS)
                continue

            _set_scale_in_protection(True)
            _current_job = job
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
                if _shutdown_requested and _shutdown_reason in ("spot_interruption", "spot_rebalance", "asg_termination"):
                    _requeue_job(job["dispatch_id"], job["lease_token"], _shutdown_reason)
                else:
                    fail_job(job["dispatch_id"], job["lease_token"], str(exc))
            finally:
                _current_job = None
                current_load = max(0, current_load - 1)
        except Exception:
            time.sleep(POLL_INTERVAL_SECONDS)

    # Graceful shutdown
    _set_scale_in_protection(False)

    # If interrupted with an active job, requeue it
    if _current_job and _shutdown_reason in ("spot_interruption", "spot_rebalance", "asg_termination"):
        _requeue_job(_current_job["dispatch_id"], _current_job["lease_token"], _shutdown_reason)

    # Deregister if fleet-registered
    if FLEET_SECRET:
        _fleet_deregister(_shutdown_reason)

    print(f"[worker] Shutdown complete. Reason: {_shutdown_reason or 'normal'}")


if __name__ == "__main__":
    main()
