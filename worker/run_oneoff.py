import sys
import traceback

import comfyui_worker as worker


def main() -> None:
    job = worker.poll(0)
    if not job:
        print("No jobs available.")
        return

    try:
        worker.process_job(job)
        print("Job completed.")
    except Exception as exc:
        worker.fail_job(job["dispatch_id"], job["lease_token"], str(exc))
        traceback.print_exc()
        raise


if __name__ == "__main__":
    main()
