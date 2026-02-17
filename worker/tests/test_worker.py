import os
import sys
import unittest
from unittest import mock

sys.path.append(os.path.dirname(os.path.dirname(__file__)))

import comfyui_worker as worker


class DummyResponse:
    def __init__(self, payload=None, content=b"data"):
        self._payload = payload or {}
        self._content = content

    def raise_for_status(self):
        return None

    def json(self):
        return self._payload

    def iter_content(self, chunk_size=1024):
        yield self._content


class WorkerTests(unittest.TestCase):
    def setUp(self):
        worker.COMFY_PROVIDER = "self_hosted"
        worker.COMFY_PROVIDERS = ""
        worker.COMFY_CLOUD_API_KEY = "cloud-key"
        worker.COMFY_CLOUD_BASE_URL = "https://cloud.comfy.org"

    def test_prepare_workflow_replaces_placeholder(self):
        workflow = {"1": {"inputs": {"path": "__INPUT_PATH__"}}}
        input_payload = {"workflow": workflow, "input_path_placeholder": "__INPUT_PATH__"}
        result = worker.prepare_workflow(input_payload, "/tmp/input.mp4")

        self.assertEqual(result["1"]["inputs"]["path"], "/tmp/input.mp4")

    def test_prepare_workflow_injects_node_field(self):
        workflow = {"10": {"inputs": {"video": ""}}}
        payload = {"workflow": workflow, "input_node_id": "10", "input_field": "video"}
        result = worker.prepare_workflow(payload, "/tmp/video.mp4")

        self.assertEqual(result["10"]["inputs"]["video"], "/tmp/video.mp4")

    def test_extract_output_file_with_node_id(self):
        outputs = {
            "5": {
                "videos": [{"filename": "out.mp4", "subfolder": "", "type": "output"}]
            }
        }
        result = worker.extract_output_file(outputs, "5")
        self.assertEqual(result["filename"], "out.mp4")

    def test_extract_output_file_without_node_id(self):
        outputs = {
            "1": {"images": [{"filename": "out.png", "subfolder": "", "type": "output"}]}
        }
        result = worker.extract_output_file(outputs, None)
        self.assertEqual(result["filename"], "out.png")

    def test_extract_output_file_raises_when_missing(self):
        with self.assertRaises(RuntimeError):
            worker.extract_output_file({}, None)

    @mock.patch("comfyui_worker.requests.get")
    def test_download_input_writes_file(self, mock_get):
        mock_get.return_value = DummyResponse(content=b"hello")
        path = worker.download_input("https://example.com/input.mp4")

        with open(path, "rb") as handle:
            self.assertEqual(handle.read(), b"hello")

        os.remove(path)

    @mock.patch("comfyui_worker.requests.put")
    def test_upload_output_uses_put(self, mock_put):
        mock_put.return_value = DummyResponse()
        with open("temp-output.bin", "wb") as handle:
            handle.write(b"data")

        try:
            worker.upload_output("https://example.com/upload", {}, "temp-output.bin")
            mock_put.assert_called_once()
        finally:
            os.remove("temp-output.bin")

    @mock.patch("comfyui_worker.time.sleep", return_value=None)
    @mock.patch("comfyui_worker.requests.get")
    @mock.patch("comfyui_worker.requests.post")
    def test_run_comfyui_success(self, mock_post, mock_get, _sleep):
        mock_post.return_value = DummyResponse(payload={"prompt_id": "123"})
        mock_get.return_value = DummyResponse(payload={
            "123": {
                "outputs": {
                    "node": {"videos": [{"filename": "out.mp4", "subfolder": "", "type": "output"}]}
                }
            }
        })

        prompt_id, outputs = worker.run_comfyui({"node": {}}, None)
        self.assertEqual(prompt_id, "123")
        self.assertIn("node", outputs)

    @mock.patch("comfyui_worker.time.sleep", return_value=None)
    @mock.patch("comfyui_worker.requests.get")
    @mock.patch("comfyui_worker.requests.post")
    def test_run_comfyui_error(self, mock_post, mock_get, _sleep):
        mock_post.return_value = DummyResponse(payload={"prompt_id": "err"})
        mock_get.return_value = DummyResponse(payload={
            "err": {"status": {"status_str": "error", "message": "fail"}}
        })

        with self.assertRaises(RuntimeError):
            worker.run_comfyui({"node": {}}, None)

    @mock.patch("comfyui_worker.requests.request")
    def test_cloud_submit_prompt_uses_api_key(self, mock_request):
        mock_request.return_value = DummyResponse(payload={"prompt_id": "cloud-1"})

        prompt_id = worker.cloud_submit_prompt({"node": {}}, None)
        self.assertEqual(prompt_id, "cloud-1")

        args, kwargs = mock_request.call_args
        headers = kwargs.get("headers", {})
        self.assertEqual(headers.get("X-API-Key"), "cloud-key")


if __name__ == "__main__":
    unittest.main()
