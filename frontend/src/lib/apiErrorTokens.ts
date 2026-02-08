import { ApiError } from "@/lib/api";

export function getRequiredTokensFromError(error: unknown): number | null {
  if (!(error instanceof ApiError)) return null;
  const payload = error.data;
  if (!payload || typeof payload !== "object") return null;
  const data = "data" in payload ? (payload as { data?: unknown }).data : null;
  if (!data || typeof data !== "object") return null;
  const required = (data as { required_tokens?: unknown }).required_tokens;
  return typeof required === "number" ? required : null;
}
