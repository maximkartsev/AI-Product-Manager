import { describe, expect, it } from "vitest";
import {
  buildWorkflowUpdateFromAnalysis,
  formatWorkflowJson,
  parseWorkflowJsonInput,
} from "../workflowJson";

describe("workflowJson helpers", () => {
  it("parses valid workflow JSON objects", () => {
    const parsed = parseWorkflowJsonInput('{"1":{"class_type":"PromptNode"}}');
    expect(parsed.ok).toBe(true);
    if (parsed.ok) {
      expect(parsed.value["1"]).toBeTruthy();
    }
  });

  it("rejects empty and invalid payloads", () => {
    expect(parseWorkflowJsonInput("   ")).toEqual({
      ok: false,
      error: "Workflow JSON is required.",
    });
    expect(parseWorkflowJsonInput("[]")).toEqual({
      ok: false,
      error: "Workflow JSON must be a JSON object.",
    });
    expect(parseWorkflowJsonInput("{bad json")).toEqual({
      ok: false,
      error: "Workflow JSON is not valid JSON.",
    });
  });

  it("formats workflow JSON with stable indentation", () => {
    const formatted = formatWorkflowJson({ node: { class_type: "PromptNode" } });
    expect(formatted).toContain('\n  "node": {');
  });

  it("maps analyzer output into workflow update payload", () => {
    const payload = buildWorkflowUpdateFromAnalysis({
      properties: [
        {
          key: "prompt",
          name: "Prompt",
          type: "text",
          required: true,
          placeholder: "__PROMPT__",
          user_configurable: true,
        },
        {
          key: "soundtrack",
          name: "Soundtrack",
          type: "audio",
          required: false,
          placeholder: "__SOUND__",
          user_configurable: false,
        },
      ],
      primary_input: null,
      output: {
        node_id: "99",
        mime_type: "video/mp4",
        extension: "mp4",
      },
      placeholder_insertions: [],
      autoscaling_hints: {
        workload_kind: "video",
        work_units_property_key: "duration",
        slo_p95_wait_seconds: null,
        slo_video_seconds_per_processing_second_p95: 0.4,
      },
    });

    expect(payload.output_node_id).toBe("99");
    expect(payload.output_extension).toBe("mp4");
    expect(payload.workload_kind).toBe("video");
    expect(payload.properties?.[1]?.type).toBe("text");
    expect(payload.slo_video_seconds_per_processing_second_p95).toBe(0.4);
  });
});

