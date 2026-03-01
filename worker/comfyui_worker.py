import hashlib
import json
import math
import mimetypes
import os
import re
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

COMFYUI_BASE_URL = os.environ.get("COMFYUI_BASE_URL", "http://localhost:8188")

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


def _parse_capabilities() -> Optional[Dict[str, Any]]:
    if not CAPABILITIES:
        return None
    try:
        return json.loads(CAPABILITIES)
    except json.JSONDecodeError:
        return {"raw": CAPABILITIES}


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
    }
    data = _backend_post("/api/worker/poll", payload)
    return data.get("data", {}).get("job")


def heartbeat(dispatch_id: int, lease_token: str) -> None:
    _backend_post("/api/worker/heartbeat", {
        "dispatch_id": dispatch_id,
        "lease_token": lease_token,
        "worker_id": WORKER_ID,
    })


def complete_job(
    dispatch_id: int,
    lease_token: str,
    provider_job_id: str,
    output_path: str,
    output_metadata: Optional[Dict[str, Any]] = None,
) -> None:
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
    if output_metadata:
        payload["output"]["metadata"] = output_metadata
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
            # Upload to ComfyUI on this self-hosted node.
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


_INPUT_TOKEN_KEYS = {
    "prompt_tokens",
    "input_tokens",
    "tokens_in",
    "prompt_token_count",
    "input_token_count",
}
_OUTPUT_TOKEN_KEYS = {
    "completion_tokens",
    "output_tokens",
    "tokens_out",
    "completion_token_count",
    "output_token_count",
}
_TOTAL_TOKEN_KEYS = {
    "total_tokens",
    "token_count",
    "total_token_count",
}
_CREDIT_KEYS = {
    "credits",
    "credit",
    "credits_used",
    "token_cost",
    "partner_tokens",
}
_COST_USD_KEYS = {
    "cost",
    "usd_cost",
    "cost_usd",
    "price_usd",
    "cost_in_usd",
}
_MODEL_KEYS = {
    "model",
    "model_name",
    "model_id",
    "engine",
    "provider_model",
    "llm_model",
    "chat_model",
}
_USAGE_CONTAINER_KEYS = {
    "usage",
    "token_usage",
    "usage_data",
    "usage_metadata",
    "billing",
    "cost_breakdown",
}
_USAGE_HINT_KEYS = (
    _INPUT_TOKEN_KEYS
    | _OUTPUT_TOKEN_KEYS
    | _TOTAL_TOKEN_KEYS
    | _CREDIT_KEYS
    | _COST_USD_KEYS
    | _MODEL_KEYS
)
_PROVIDER_HINTS = {
    "openai": "openai",
    "gemini": "google",
    "google": "google",
    "anthropic": "anthropic",
    "claude": "anthropic",
    "kling": "kling",
    "runway": "runway",
    "stability": "stability",
    "vidu": "vidu",
    "tripo": "tripo",
    "luma": "luma",
    "minimax": "minimax",
    "ideogram": "ideogram",
    "pixverse": "pixverse",
    "recraft": "recraft",
}
_TEXT_PATTERNS = {
    "input_tokens": re.compile(r"(?:input|prompt)\s*tokens?\D+([0-9][0-9,]*)", re.IGNORECASE),
    "output_tokens": re.compile(r"(?:output|completion)\s*tokens?\D+([0-9][0-9,]*)", re.IGNORECASE),
    "total_tokens": re.compile(r"total\s*tokens?\D+([0-9][0-9,]*)", re.IGNORECASE),
    "credits": re.compile(r"credits?\D+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE),
    "cost_usd_reported": re.compile(r"(?:cost|price)\D+\$?\s*([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE),
}


def _normalize_key(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.strip().lower()).strip("_")


def _to_float(value: Any) -> Optional[float]:
    if value is None or isinstance(value, bool):
        return None
    try:
        if isinstance(value, str):
            cleaned = value.strip().replace(",", "")
            if cleaned == "":
                return None
            number = float(cleaned)
        else:
            number = float(value)
    except (TypeError, ValueError):
        return None
    if not math.isfinite(number):
        return None
    return number


def _to_int(value: Any) -> Optional[int]:
    number = _to_float(value)
    if number is None or number < 0:
        return None
    return int(round(number))


def _sanitize_json(value: Any, depth: int = 0) -> Any:
    if depth > 4:
        return None
    if isinstance(value, dict):
        result: Dict[str, Any] = {}
        for index, (key, nested) in enumerate(value.items()):
            if index >= 30:
                result["__truncated__"] = True
                break
            key_str = str(key)
            if len(key_str) > 80:
                key_str = key_str[:80] + "...(truncated)"
            result[key_str] = _sanitize_json(nested, depth + 1)
        return result
    if isinstance(value, list):
        items = [_sanitize_json(item, depth + 1) for item in value[:30]]
        if len(value) > 30:
            items.append({"__truncated__": True})
        return items
    if isinstance(value, str):
        text = value.strip()
        if len(text) > 800:
            return text[:800] + "...(truncated)"
        return text
    if isinstance(value, (int, float, bool)) or value is None:
        return value
    text = str(value)
    return text[:200] + ("...(truncated)" if len(text) > 200 else "")


def _iter_nested_dicts(payload: Any, depth: int = 0):
    if depth > 5:
        return
    if isinstance(payload, dict):
        yield payload
        for nested in payload.values():
            yield from _iter_nested_dicts(nested, depth + 1)
    elif isinstance(payload, list):
        for nested in payload[:30]:
            yield from _iter_nested_dicts(nested, depth + 1)


def _lookup_number(payload: Any, keys: set[str]) -> Optional[float]:
    normalized_keys = {_normalize_key(key) for key in keys}
    for dictionary in _iter_nested_dicts(payload):
        for key, value in dictionary.items():
            if _normalize_key(str(key)) not in normalized_keys:
                continue
            number = _to_float(value)
            if number is not None:
                return number
    return None


def _lookup_string(payload: Any, keys: set[str]) -> Optional[str]:
    normalized_keys = {_normalize_key(key) for key in keys}
    for dictionary in _iter_nested_dicts(payload):
        for key, value in dictionary.items():
            if _normalize_key(str(key)) not in normalized_keys:
                continue
            if value is None:
                continue
            text = str(value).strip()
            if not text:
                continue
            if len(text) > 255:
                text = text[:255] + "...(truncated)"
            return text
    return None


def _dict_has_usage_hint(payload: Dict[str, Any]) -> bool:
    return any(_normalize_key(str(key)) in _USAGE_HINT_KEYS for key in payload.keys())


def _extract_usage_payload(node_output: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    for key in _USAGE_CONTAINER_KEYS:
        value = node_output.get(key)
        if isinstance(value, dict):
            return value
    for dictionary in _iter_nested_dicts(node_output):
        if dictionary is node_output:
            continue
        if _dict_has_usage_hint(dictionary):
            return dictionary
    return None


def _collect_text(payload: Any, acc: List[str], depth: int = 0) -> None:
    if depth > 4 or len(acc) >= 25:
        return
    if isinstance(payload, str):
        text = payload.strip()
        if text:
            acc.append(text[:400])
        return
    if isinstance(payload, dict):
        for key, value in payload.items():
            normalized = _normalize_key(str(key))
            if normalized in {"filename", "subfolder", "type"}:
                continue
            _collect_text(value, acc, depth + 1)
            if len(acc) >= 25:
                return
    elif isinstance(payload, list):
        for value in payload[:25]:
            _collect_text(value, acc, depth + 1)
            if len(acc) >= 25:
                return


def _extract_metrics_from_text(payload: Any) -> Dict[str, Optional[float]]:
    chunks: List[str] = []
    _collect_text(payload, chunks)
    if not chunks:
        return {}
    text = "\n".join(chunks)[:4000]
    metrics: Dict[str, Optional[float]] = {}
    for metric, pattern in _TEXT_PATTERNS.items():
        match = pattern.search(text)
        if not match:
            continue
        metrics[metric] = _to_float(match.group(1))
    return metrics


def _detect_provider(node_class_type: str, node_inputs: Dict[str, Any]) -> str:
    haystack_parts: List[str] = [node_class_type]
    provider_hint = _lookup_string(node_inputs, {"provider", "vendor", "service"})
    if provider_hint:
        haystack_parts.append(provider_hint)
    haystack = " ".join(haystack_parts).lower()
    for hint, provider in _PROVIDER_HINTS.items():
        if hint in haystack:
            return provider
    if "api" in haystack:
        return "comfy_partner"
    return "unknown"


def extract_partner_usage_events(
    workflow: Dict[str, Any],
    history_entry: Dict[str, Any],
) -> List[Dict[str, Any]]:
    outputs = history_entry.get("outputs")
    if not isinstance(outputs, dict):
        return []

    events: List[Dict[str, Any]] = []
    for node_id, node_output in outputs.items():
        if not isinstance(node_output, dict):
            continue

        node_id_str = str(node_id)
        workflow_node = workflow.get(node_id_str, {}) if isinstance(workflow, dict) else {}
        workflow_inputs = workflow_node.get("inputs", {}) if isinstance(workflow_node, dict) else {}
        node_class_type = (
            str(workflow_node.get("class_type") or node_output.get("class_type") or "unknown")
            if isinstance(workflow_node, dict)
            else "unknown"
        )
        node_display_name = None
        if isinstance(workflow_node, dict):
            meta = workflow_node.get("_meta")
            if isinstance(meta, dict):
                title = meta.get("title")
                if title:
                    node_display_name = str(title)

        usage_payload = _extract_usage_payload(node_output)
        ui_payload = node_output.get("ui") if isinstance(node_output.get("ui"), (dict, list)) else None

        input_tokens = _lookup_number(usage_payload, _INPUT_TOKEN_KEYS) if usage_payload else None
        output_tokens = _lookup_number(usage_payload, _OUTPUT_TOKEN_KEYS) if usage_payload else None
        total_tokens = _lookup_number(usage_payload, _TOTAL_TOKEN_KEYS) if usage_payload else None
        credits = _lookup_number(usage_payload, _CREDIT_KEYS) if usage_payload else None
        cost_usd = _lookup_number(usage_payload, _COST_USD_KEYS) if usage_payload else None

        if input_tokens is None:
            input_tokens = _lookup_number(node_output, _INPUT_TOKEN_KEYS)
        if output_tokens is None:
            output_tokens = _lookup_number(node_output, _OUTPUT_TOKEN_KEYS)
        if total_tokens is None:
            total_tokens = _lookup_number(node_output, _TOTAL_TOKEN_KEYS)
        if credits is None:
            credits = _lookup_number(node_output, _CREDIT_KEYS)
        if cost_usd is None:
            cost_usd = _lookup_number(node_output, _COST_USD_KEYS)

        text_metrics = _extract_metrics_from_text(ui_payload if ui_payload is not None else node_output)
        if input_tokens is None:
            input_tokens = text_metrics.get("input_tokens")
        if output_tokens is None:
            output_tokens = text_metrics.get("output_tokens")
        if total_tokens is None:
            total_tokens = text_metrics.get("total_tokens")
        if credits is None:
            credits = text_metrics.get("credits")
        if cost_usd is None:
            cost_usd = text_metrics.get("cost_usd_reported")

        input_tokens_int = _to_int(input_tokens)
        output_tokens_int = _to_int(output_tokens)
        total_tokens_int = _to_int(total_tokens)
        if total_tokens_int is None and input_tokens_int is not None and output_tokens_int is not None:
            total_tokens_int = input_tokens_int + output_tokens_int

        credits_float = _to_float(credits)
        cost_usd_float = _to_float(cost_usd)
        model = _lookup_string(workflow_inputs, _MODEL_KEYS)
        if model is None and usage_payload is not None:
            model = _lookup_string(usage_payload, _MODEL_KEYS)
        provider = _detect_provider(node_class_type, workflow_inputs if isinstance(workflow_inputs, dict) else {})

        if (
            input_tokens_int is None
            and output_tokens_int is None
            and total_tokens_int is None
            and credits_float is None
            and cost_usd_float is None
            and usage_payload is None
            and ui_payload is None
        ):
            continue

        event: Dict[str, Any] = {
            "node_id": node_id_str,
            "node_class_type": node_class_type,
            "node_display_name": node_display_name,
            "provider": provider,
            "model": model,
            "input_tokens": input_tokens_int,
            "output_tokens": output_tokens_int,
            "total_tokens": total_tokens_int,
            "credits": round(credits_float, 6) if credits_float is not None else None,
            "cost_usd_reported": round(cost_usd_float, 8) if cost_usd_float is not None else None,
            "usage_json": _sanitize_json(usage_payload) if usage_payload is not None else None,
            "ui_json": _sanitize_json(ui_payload) if ui_payload is not None else None,
        }
        events.append(event)

    return events


def run_comfyui(
    workflow: Dict[str, Any],
    output_node_id: Optional[str],
    extra_data: Optional[Dict[str, Any]] = None
) -> Tuple[str, Dict[str, Any], Dict[str, Any]]:
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
                return str(prompt_id), outputs, record

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

    input_path = None
    output_path = None

    # Handle asset pipeline if assets are present in input_payload
    assets = input_payload.get("assets")
    asset_placeholder_map: Optional[Dict[str, str]] = None

    try:
        if assets and isinstance(assets, list):
            asset_placeholder_map = download_and_upload_assets(assets, COMFYUI_BASE_URL)

        # Always run against self-hosted ComfyUI on this AWS node.
        input_path = download_input(input_url) if input_url else None
        workflow = prepare_workflow(input_payload, input_path, asset_placeholder_map)

        extra_data = input_payload.get("extra_data")
        provider_job_id, outputs, history_entry = run_comfyui(workflow, output_node_id, extra_data)
        output_file_info = extract_output_file(outputs, output_node_id)
        output_path = download_comfyui_output(output_file_info)

        output_metadata: Dict[str, Any] = {}
        try:
            usage_events = extract_partner_usage_events(workflow, history_entry)
            if usage_events:
                output_metadata["partner_usage_events"] = usage_events
        except Exception as exc:
            print(f"[worker] Partner usage extraction skipped: {exc}")

        upload_output(output_url, output_headers, output_path)
        complete_job(
            dispatch_id,
            lease_token,
            provider_job_id,
            output_path,
            output_metadata if output_metadata else None,
        )
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
