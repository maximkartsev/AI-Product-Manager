export type BlackboxInputParseResult =
  | { ok: true; value: Record<string, unknown> }
  | { ok: false; error: string };

export type BlackboxRunInputNumbers = {
  effectId: number;
  revisionId: number;
  executionEnvironmentId: number;
  inputFileId: number;
  count: number;
};

export function validateBlackboxRunInputNumbers(input: BlackboxRunInputNumbers): string | null {
  if (!Number.isInteger(input.effectId) || input.effectId <= 0) {
    return "Select an effect.";
  }

  if (!Number.isInteger(input.revisionId) || input.revisionId <= 0) {
    return "Select an effect revision.";
  }

  if (!Number.isInteger(input.executionEnvironmentId) || input.executionEnvironmentId <= 0) {
    return "Select a test ASG execution environment.";
  }

  if (!Number.isInteger(input.inputFileId) || input.inputFileId <= 0) {
    return "Input file ID is required.";
  }

  if (!Number.isInteger(input.count) || input.count <= 0) {
    return "Count must be a positive number.";
  }

  if (input.count > 200) {
    return "Count must be 200 or less.";
  }

  return null;
}

export function parseBlackboxInputPayload(raw: string): BlackboxInputParseResult {
  const trimmed = raw.trim();
  if (!trimmed) {
    return { ok: true, value: {} };
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(trimmed);
  } catch {
    return { ok: false, error: "Input payload is not valid JSON." };
  }

  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
    return { ok: false, error: "Input payload must be a JSON object." };
  }

  return { ok: true, value: parsed as Record<string, unknown> };
}

export function parseBlackboxRunCounts(raw: string): number[] {
  const tokens = raw
    .split(",")
    .map((part) => part.trim())
    .filter((part) => part.length > 0);

  const values = tokens
    .map((part) => Number(part))
    .filter((value) => Number.isInteger(value) && value > 0)
    .map((value) => Number(value));

  if (values.length === 0) {
    return [1, 10, 100];
  }

  return Array.from(new Set(values)).sort((a, b) => a - b);
}

export function extractBlackboxInputFromTestInputSet(inputJson: unknown): {
  input_file_id?: number;
  input_payload?: Record<string, unknown>;
} | null {
  if (!inputJson || typeof inputJson !== "object" || Array.isArray(inputJson)) {
    return null;
  }

  const value = inputJson as Record<string, unknown>;
  const nested = value.blackbox_input;
  const source = nested && typeof nested === "object" && !Array.isArray(nested)
    ? (nested as Record<string, unknown>)
    : value;

  const inputFileId = Number(source.input_file_id);
  if (!Number.isInteger(inputFileId) || inputFileId <= 0) {
    return null;
  }

  const inputPayloadRaw = source.input_payload;
  const inputPayload = inputPayloadRaw && typeof inputPayloadRaw === "object" && !Array.isArray(inputPayloadRaw)
    ? (inputPayloadRaw as Record<string, unknown>)
    : {};

  return {
    input_file_id: inputFileId,
    input_payload: inputPayload,
  };
}

