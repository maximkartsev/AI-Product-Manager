import type { StudioDevNodeRunInputPayload } from "@/lib/api";

export type InteractiveRunParseResult =
  | { ok: true; value: StudioDevNodeRunInputPayload }
  | { ok: false; error: string };

export function parseInteractiveRunInput(raw: string): InteractiveRunParseResult {
  const trimmed = raw.trim();
  if (!trimmed) {
    return { ok: false, error: "Input payload JSON is required." };
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

  const payload = parsed as Record<string, unknown>;
  const inputPath = typeof payload.input_path === "string" ? payload.input_path.trim() : "";
  if (!inputPath) {
    return { ok: false, error: "Input payload must include input_path." };
  }

  const propertiesRaw = payload.properties;
  let properties: Record<string, unknown> | undefined = undefined;
  if (propertiesRaw !== undefined) {
    if (!propertiesRaw || typeof propertiesRaw !== "object" || Array.isArray(propertiesRaw)) {
      return { ok: false, error: "Input payload properties must be a JSON object when provided." };
    }
    properties = propertiesRaw as Record<string, unknown>;
  }

  return {
    ok: true,
    value: {
      input_path: inputPath,
      input_disk: typeof payload.input_disk === "string" ? payload.input_disk : undefined,
      input_name: typeof payload.input_name === "string" ? payload.input_name : undefined,
      input_mime_type: typeof payload.input_mime_type === "string" ? payload.input_mime_type : undefined,
      properties,
    },
  };
}

export function extractInteractiveRunInputFromTestInputSet(inputJson: unknown): StudioDevNodeRunInputPayload | null {
  if (!inputJson || typeof inputJson !== "object" || Array.isArray(inputJson)) {
    return null;
  }

  const objectValue = inputJson as Record<string, unknown>;
  const nested = objectValue.input_payload;
  if (nested && typeof nested === "object" && !Array.isArray(nested)) {
    const nestedPath = typeof (nested as Record<string, unknown>).input_path === "string"
      ? ((nested as Record<string, unknown>).input_path as string).trim()
      : "";
    return nestedPath ? (nested as StudioDevNodeRunInputPayload) : null;
  }

  const directPath = typeof objectValue.input_path === "string" ? objectValue.input_path.trim() : "";
  if (!directPath) {
    return null;
  }

  return objectValue as StudioDevNodeRunInputPayload;
}

