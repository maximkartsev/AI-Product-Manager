import { ApiError } from "@/lib/api";

/** Extract field-level validation errors from an ApiError response */
export function extractFieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) return {};
  const payload = error.data;
  if (!payload || typeof payload !== "object") return {};
  const data = (payload as Record<string, unknown>).data;
  if (!data || typeof data !== "object") return {};

  const errors: Record<string, string> = {};
  for (const [field, messages] of Object.entries(data as Record<string, unknown>)) {
    if (Array.isArray(messages) && messages.length > 0) {
      errors[field] = String(messages[0]);
    } else if (typeof messages === "string") {
      errors[field] = messages;
    }
  }
  return errors;
}

/** Extract a user-friendly message from an ApiError */
export function extractErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    return error.message || fallback;
  }
  return fallback;
}
