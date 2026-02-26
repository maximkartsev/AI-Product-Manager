import type { AdminWorkflowPayload, StudioWorkflowAnalyzeResult, WorkflowProperty } from "@/lib/api";

export type WorkflowJsonParseResult =
  | { ok: true; value: Record<string, unknown> }
  | { ok: false; error: string };

export function parseWorkflowJsonInput(raw: string): WorkflowJsonParseResult {
  const trimmed = raw.trim();
  if (!trimmed) {
    return { ok: false, error: "Workflow JSON is required." };
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(trimmed);
  } catch {
    return { ok: false, error: "Workflow JSON is not valid JSON." };
  }

  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
    return { ok: false, error: "Workflow JSON must be a JSON object." };
  }

  const objectValue = parsed as Record<string, unknown>;
  if (Object.keys(objectValue).length === 0) {
    return { ok: false, error: "Workflow JSON cannot be empty." };
  }

  return { ok: true, value: objectValue };
}

export function formatWorkflowJson(value: Record<string, unknown>): string {
  return JSON.stringify(value, null, 2);
}

function normalizePropertyType(type: string | undefined): WorkflowProperty["type"] {
  if (type === "image" || type === "video") {
    return type;
  }
  return "text";
}

export function buildWorkflowUpdateFromAnalysis(
  analysis: StudioWorkflowAnalyzeResult | null | undefined,
): AdminWorkflowPayload {
  if (!analysis) {
    return {};
  }

  const properties: WorkflowProperty[] = (analysis.properties ?? []).map((property) => ({
    key: property.key,
    name: property.name || property.key,
    type: normalizePropertyType(property.type),
    placeholder: property.placeholder || `__${property.key.toUpperCase()}__`,
    required: Boolean(property.required),
    user_configurable: property.user_configurable ?? true,
  }));

  return {
    properties,
    output_node_id: analysis.output?.node_id ?? null,
    output_extension: analysis.output?.extension ?? null,
    output_mime_type: analysis.output?.mime_type ?? null,
    workload_kind: analysis.autoscaling_hints?.workload_kind ?? null,
    work_units_property_key: analysis.autoscaling_hints?.work_units_property_key ?? null,
    slo_p95_wait_seconds: analysis.autoscaling_hints?.slo_p95_wait_seconds ?? null,
    slo_video_seconds_per_processing_second_p95:
      analysis.autoscaling_hints?.slo_video_seconds_per_processing_second_p95 ?? null,
  };
}

