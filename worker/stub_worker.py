import time
import uuid

import comfyui_worker as base_worker


def process_job(job: dict) -> None:
    dispatch_id = job["dispatch_id"]
    lease_token = job["lease_token"]
    input_url = job.get("input_url")
    output_url = job.get("output_url")
    output_headers = job.get("output_headers", {}) or {}

    if not input_url:
        raise RuntimeError("Missing input_url for stub processing.")
    if not output_url:
        raise RuntimeError("Missing output_url for stub processing.")

    input_path = None
    try:
        input_path = base_worker.download_input(input_url)
        base_worker.upload_output(output_url, output_headers, input_path)
        provider_job_id = f"stub-{uuid.uuid4()}"
        base_worker.complete_job(dispatch_id, lease_token, provider_job_id, input_path)
    finally:
        base_worker._safe_unlink(input_path)


def main() -> None:
    current_load = 0
    while True:
        try:
            job = base_worker.poll(current_load)
            if not job:
                time.sleep(base_worker.POLL_INTERVAL_SECONDS)
                continue

            current_load += 1
            last_heartbeat = time.time()

            try:
                while True:
                    now = time.time()
                    if now - last_heartbeat >= base_worker.HEARTBEAT_INTERVAL_SECONDS:
                        base_worker.heartbeat(job["dispatch_id"], job["lease_token"])
                        last_heartbeat = now
                    process_job(job)
                    break
            except Exception as exc:
                base_worker.fail_job(job["dispatch_id"], job["lease_token"], str(exc))
            finally:
                current_load = max(0, current_load - 1)
        except Exception:
            time.sleep(base_worker.POLL_INTERVAL_SECONDS)


if __name__ == "__main__":
    main()
