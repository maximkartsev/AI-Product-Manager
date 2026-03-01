import os
import sys
import tempfile
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
        worker.COMFYUI_BASE_URL = "http://localhost:8188"

    def test_prepare_workflow_replaces_placeholder(self):
        workflow = {"1": {"inputs": {"path": "__INPUT_PATH__"}}}
        input_payload = {"workflow": workflow, "input_path_placeholder": "__INPUT_PATH__"}
        with tempfile.NamedTemporaryFile(delete=False, suffix=".mp4") as handle:
            input_path = handle.name
        normalized_input_path = input_path.replace("\\", "/")
        try:
            result = worker.prepare_workflow(input_payload, normalized_input_path)
            self.assertEqual(result["1"]["inputs"]["path"], normalized_input_path)
        finally:
            os.remove(input_path)

    def test_prepare_workflow_injects_node_field(self):
        workflow = {"10": {"inputs": {"video": ""}}}
        payload = {"workflow": workflow, "input_node_id": "10", "input_field": "video"}
        with tempfile.NamedTemporaryFile(delete=False, suffix=".mp4") as handle:
            input_path = handle.name
        normalized_input_path = input_path.replace("\\", "/")
        try:
            result = worker.prepare_workflow(payload, normalized_input_path)
            self.assertEqual(result["10"]["inputs"]["video"], normalized_input_path)
        finally:
            os.remove(input_path)

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

        prompt_id, outputs, record = worker.run_comfyui({"node": {}}, None)
        self.assertEqual(prompt_id, "123")
        self.assertIn("node", outputs)
        self.assertIn("outputs", record)

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

    def test_extract_partner_usage_events_from_structured_usage(self):
        workflow = {
            "18": {
                "class_type": "OpenAIChat",
                "inputs": {"model": "gpt-4o-mini"},
                "_meta": {"title": "OpenAI Chat"},
            }
        }
        history = {
            "outputs": {
                "18": {
                    "usage": {
                        "prompt_tokens": 120,
                        "completion_tokens": 45,
                        "total_tokens": 165,
                    }
                }
            }
        }

        events = worker.extract_partner_usage_events(workflow, history)
        self.assertEqual(len(events), 1)
        event = events[0]
        self.assertEqual(event["provider"], "openai")
        self.assertEqual(event["model"], "gpt-4o-mini")
        self.assertEqual(event["input_tokens"], 120)
        self.assertEqual(event["output_tokens"], 45)
        self.assertEqual(event["total_tokens"], 165)
        self.assertEqual(event["node_class_type"], "OpenAIChat")

    def test_extract_partner_usage_events_from_ui_text(self):
        workflow = {
            "9": {
                "class_type": "GoogleGemini",
                "inputs": {"model_name": "gemini-2.5-pro"},
            }
        }
        history = {
            "outputs": {
                "9": {
                    "ui": {
                        "text": [
                            "Prompt tokens: 210",
                            "Completion tokens: 88",
                            "Total tokens: 298",
                            "Credits: 3.5",
                            "Cost: $0.0245",
                        ]
                    }
                }
            }
        }

        events = worker.extract_partner_usage_events(workflow, history)
        self.assertEqual(len(events), 1)
        event = events[0]
        self.assertEqual(event["provider"], "google")
        self.assertEqual(event["model"], "gemini-2.5-pro")
        self.assertEqual(event["input_tokens"], 210)
        self.assertEqual(event["output_tokens"], 88)
        self.assertEqual(event["total_tokens"], 298)
        self.assertEqual(event["credits"], 3.5)
        self.assertEqual(event["cost_usd_reported"], 0.0245)

    def test_extract_partner_usage_events_skips_empty_outputs(self):
        events = worker.extract_partner_usage_events({"1": {"class_type": "SaveImage"}}, {"outputs": {"1": {}}})
        self.assertEqual(events, [])


if __name__ == "__main__":
    unittest.main()
