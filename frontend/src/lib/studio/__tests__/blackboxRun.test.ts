import { describe, expect, it } from "vitest";
import {
  extractBlackboxInputFromTestInputSet,
  parseBlackboxInputPayload,
  parseBlackboxRunCounts,
} from "../blackboxRun";

describe("parseBlackboxInputPayload", () => {
  it("accepts empty payload as empty object", () => {
    const result = parseBlackboxInputPayload("   ");
    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.value).toEqual({});
    }
  });

  it("rejects non-object JSON payloads", () => {
    const result = parseBlackboxInputPayload("[]");
    expect(result.ok).toBe(false);
  });
});

describe("parseBlackboxRunCounts", () => {
  it("normalizes run counts from csv input", () => {
    expect(parseBlackboxRunCounts("10, 1, 10, 100")).toEqual([1, 10, 100]);
  });

  it("falls back to defaults for invalid input", () => {
    expect(parseBlackboxRunCounts("a,0,-5")).toEqual([1, 10, 100]);
  });
});

describe("extractBlackboxInputFromTestInputSet", () => {
  it("extracts direct shape", () => {
    const value = extractBlackboxInputFromTestInputSet({
      input_file_id: 42,
      input_payload: { prompt: "x" },
    });
    expect(value?.input_file_id).toBe(42);
    expect(value?.input_payload?.prompt).toBe("x");
  });

  it("extracts nested blackbox_input shape", () => {
    const value = extractBlackboxInputFromTestInputSet({
      blackbox_input: {
        input_file_id: 77,
        input_payload: { style: "cinematic" },
      },
    });
    expect(value?.input_file_id).toBe(77);
  });
});

