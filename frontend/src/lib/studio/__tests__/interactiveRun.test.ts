import { describe, expect, it } from "vitest";
import {
  extractInteractiveRunInputFromTestInputSet,
  parseInteractiveRunInput,
} from "../interactiveRun";

describe("parseInteractiveRunInput", () => {
  it("parses valid input payload JSON", () => {
    const result = parseInteractiveRunInput(
      JSON.stringify({
        input_path: "inputs/source.mp4",
        input_disk: "s3",
        properties: {
          prompt: "cinematic",
        },
      }),
    );

    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.value.input_path).toBe("inputs/source.mp4");
      expect(result.value.input_disk).toBe("s3");
      expect(result.value.properties?.prompt).toBe("cinematic");
    }
  });

  it("rejects payloads without input_path", () => {
    const result = parseInteractiveRunInput(JSON.stringify({ properties: { prompt: "x" } }));
    expect(result.ok).toBe(false);
  });
});

describe("extractInteractiveRunInputFromTestInputSet", () => {
  it("extracts nested input_payload shape", () => {
    const payload = extractInteractiveRunInputFromTestInputSet({
      input_payload: {
        input_path: "inputs/nested.mp4",
        input_disk: "s3",
      },
    });
    expect(payload?.input_path).toBe("inputs/nested.mp4");
  });

  it("extracts direct payload shape", () => {
    const payload = extractInteractiveRunInputFromTestInputSet({
      input_path: "inputs/direct.mp4",
      input_disk: "s3",
    });
    expect(payload?.input_path).toBe("inputs/direct.mp4");
  });
});

