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
    const message = String(error.message || fallback).trim();
    if (message && message !== "Validation Error") {
      return message;
    }

    // If the backend returns a generic "Validation Error", prefer the first
    // field-level message (it is usually more actionable).
    const fieldErrors = extractFieldErrors(error);
    const firstFieldError = Object.values(fieldErrors)[0];
    if (firstFieldError) return firstFieldError;

    return message || fallback;
  }
  return fallback;
}
