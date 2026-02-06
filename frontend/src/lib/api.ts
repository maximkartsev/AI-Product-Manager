/**
 * Canonical frontend API module (types + request helpers + resource functions).
 *
 * Keep these in sync with backend controllers/resources.
 */

// ---- Types (DTOs)

export type TenantInfo = {
  id: string;
  domain: string;
  db_pool: string;
};

export type RegisterRequest = {
  name: string;
  email: string;
  password: string;
  c_password: string;
};

export type LoginRequest = {
  email: string;
  password: string;
};

export type AuthSuccessData = {
  token: string;
  name: string;
  tenant?: TenantInfo;
};

export type WalletData = {
  balance: number;
};

export type MeData = {
  id: number;
  name: string;
  email: string;
  is_admin?: boolean;
};

export type UploadInitRequest = {
  effect_id: number;
  mime_type: string;
  size: number;
  original_filename: string;
  file_hash?: string | null;
};

export type UploadInitData = {
  file: {
    id: number;
  };
  upload_url: string;
  upload_headers: Record<string, string | string[]>;
  expires_in: number;
};

export type VideoCreateRequest = {
  effect_id: number;
  original_file_id: number;
  title?: string | null;
};

export type VideoData = {
  id: number;
  status: string;
  effect_id: number;
  original_file_id: number | null;
  processed_file_id?: number | null;
};

export type AiJobRequest = {
  effect_id: number;
  idempotency_key: string;
  provider?: string | null;
  video_id?: number | null;
  input_file_id?: number | null;
  input_payload?: Record<string, unknown> | null;
  priority?: number | null;
};

export type AiJobData = {
  id: number;
  status: string;
  requested_tokens: number;
};

export type ApiEffect = {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  thumbnail_url?: string | null;
  preview_video_url?: string | null;
  credits_cost?: number | null;
  is_premium: boolean;
  is_active: boolean;
};

export type Article = {
  id: number;
  title: string;
  sub_title?: string | null;
  state: string;
  content?: string | null;
  published_at?: string | null;
};

export type ArticleIndexData = {
  items: Article[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order: string;
  search: string | null;
};

// ---- Envelope + errors

export type ApiEnvelope<T> = {
  success: boolean;
  message?: string | null;
  data?: T;
};

export class ApiError extends Error {
  readonly status: number;
  readonly data?: unknown;

  constructor(opts: { message: string; status: number; data?: unknown; cause?: unknown }) {
    super(opts.message);
    this.name = "ApiError";
    this.status = opts.status;
    this.data = opts.data;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).cause = opts.cause;
  }
}

export function isApiEnvelope(value: unknown): value is ApiEnvelope<unknown> {
  if (!value || typeof value !== "object") return false;
  return "success" in value && typeof (value as { success?: unknown }).success === "boolean";
}

// ---- Auth (client storage)

const TOKEN_STORAGE_KEY = "auth_token";
const TENANT_DOMAIN_STORAGE_KEY = "tenant_domain";

export function getAccessToken(): string | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage.getItem(TOKEN_STORAGE_KEY);
  } catch {
    return null;
  }
}

export function setAccessToken(token: string): void {
  if (typeof window === "undefined") return;
  window.localStorage.setItem(TOKEN_STORAGE_KEY, token);
}

export function clearAccessToken(): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(TOKEN_STORAGE_KEY);
}

export function getTenantDomain(): string | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage.getItem(TENANT_DOMAIN_STORAGE_KEY);
  } catch {
    return null;
  }
}

export function setTenantDomain(domain: string): void {
  if (typeof window === "undefined") return;
  window.localStorage.setItem(TENANT_DOMAIN_STORAGE_KEY, domain);
}

export function clearTenantDomain(): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(TENANT_DOMAIN_STORAGE_KEY);
}

// ---- Request helpers

type Query = Record<string, string | number | boolean | null | undefined>;

function resolveApiBaseUrl(): string {
  const baseUrl = process.env.NEXT_PUBLIC_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL;
  if (!baseUrl) {
    throw new ApiError({
      status: 0,
      message:
        "Missing NEXT_PUBLIC_API_BASE_URL (or NEXT_PUBLIC_API_URL). Set it to your backend API base (e.g. http://localhost:80/api).",
    });
  }
  return baseUrl.replace(/\/$/, "");
}

function getEffectiveApiBaseUrl(): string {
  const base = resolveApiBaseUrl();
  const tenantDomain = getTenantDomain();
  if (!tenantDomain) return base;

  try {
    const url = new URL(base);
    url.hostname = tenantDomain;
    return url.toString().replace(/\/$/, "");
  } catch {
    return base;
  }
}

function buildUrl(path: string, query?: Query): string {
  const base = getEffectiveApiBaseUrl();
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  const url = new URL(`${base}${normalizedPath}`);

  if (query) {
    for (const [k, v] of Object.entries(query)) {
      if (v === undefined || v === null) continue;
      url.searchParams.set(k, String(v));
    }
  }

  return url.toString();
}

async function safeParseJson(response: Response): Promise<unknown> {
  const contentType = response.headers.get("content-type") || "";
  if (!contentType.includes("application/json")) {
    return null;
  }
  try {
    return await response.json();
  } catch {
    return null;
  }
}

export async function apiRequest<TData>(
  path: string,
  opts?: {
    method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
    query?: Query;
    body?: unknown;
    token?: string | null;
    headers?: HeadersInit;
  },
): Promise<TData> {
  const method = opts?.method ?? "GET";
  const token = opts?.token ?? getAccessToken();

  const headers: HeadersInit = {
    Accept: "application/json",
    ...(opts?.headers ?? {}),
  };

  let body: BodyInit | undefined;
  if (opts?.body !== undefined) {
    (headers as Record<string, string>)["Content-Type"] =
      (headers as Record<string, string>)["Content-Type"] ?? "application/json";
    body = JSON.stringify(opts.body);
  }

  if (token) {
    (headers as Record<string, string>)["Authorization"] = `Bearer ${token}`;
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path, opts?.query), {
      method,
      headers,
      body,
      credentials: "include",
    });
  } catch (cause) {
    throw new ApiError({
      status: 0,
      message: "Network error while calling the API.",
      cause,
    });
  }

  const payload = await safeParseJson(response);
  const maybeEnvelope = payload as ApiEnvelope<unknown> | null;

  if (!response.ok) {
    const message =
      (maybeEnvelope && isApiEnvelope(maybeEnvelope) && maybeEnvelope.message) ||
      response.statusText ||
      "Request failed";

    throw new ApiError({
      status: response.status,
      message: String(message),
      data: payload,
    });
  }

  if (maybeEnvelope && isApiEnvelope(maybeEnvelope)) {
    return maybeEnvelope.data as TData;
  }

  return payload as TData;
}

export function apiGet<TData>(path: string, query?: Query, token?: string | null) {
  return apiRequest<TData>(path, { method: "GET", query, token });
}

export function apiPost<TData>(path: string, body?: unknown, token?: string | null) {
  return apiRequest<TData>(path, { method: "POST", body, token });
}

// ---- Resource functions (preferred API surface)

export function register(payload: RegisterRequest): Promise<AuthSuccessData> {
  return apiPost<AuthSuccessData>("/register", payload);
}

export function login(payload: LoginRequest): Promise<AuthSuccessData> {
  return apiPost<AuthSuccessData>("/login", payload);
}

export function getWallet(): Promise<WalletData> {
  return apiGet<WalletData>("/wallet");
}

export function getMe(): Promise<MeData> {
  return apiGet<MeData>("/me");
}

export function initVideoUpload(payload: UploadInitRequest): Promise<UploadInitData> {
  return apiPost<UploadInitData>("/videos/uploads", payload);
}

export function createVideo(payload: VideoCreateRequest): Promise<VideoData> {
  return apiPost<VideoData>("/videos", payload);
}

export function submitAiJob(payload: AiJobRequest): Promise<AiJobData> {
  return apiPost<AiJobData>("/ai-jobs", payload);
}

export function getEffects(): Promise<ApiEffect[]> {
  return apiGet<ApiEffect[]>("/effects");
}

export function getEffect(slug: string): Promise<ApiEffect> {
  return apiGet<ApiEffect>(`/effects/${encodeURIComponent(slug)}`);
}

export function getArticles(
  params?: { search?: string | null; page?: number; perPage?: number; order?: string | null },
  token?: string | null,
): Promise<ArticleIndexData> {
  const query: Query = {
    search: params?.search ?? undefined,
    page: params?.page ?? undefined,
    perPage: params?.perPage ?? undefined,
    order: params?.order ?? undefined,
  };
  return apiGet<ArticleIndexData>("/articles", query, token);
}

export type ColumnConfig = {
  key: string;
  label: string;
};

export async function getAvailableColumns(entityClass: string): Promise<ColumnConfig[]> {
  const data = await apiRequest<{ columns: ColumnConfig[] }>(`/columns?class=${encodeURIComponent(entityClass)}`, {
    method: "GET",
  });
  return data.columns ?? [];
}

export type EffectUploadInitRequest = {
  kind: "workflow" | "thumbnail" | "preview_video";
  mime_type: string;
  size: number;
  original_filename: string;
};

export type EffectUploadInitData = {
  path: string;
  upload_url: string;
  upload_headers: Record<string, string | string[]>;
  expires_in: number;
  public_url?: string | null;
};

export type AdminEffect = {
  id: number;
  name?: string;
  slug?: string;
  description?: string | null;
  type?: string | null;
  preview_url?: string | null;
  thumbnail_url?: string | null;
  preview_video_url?: string | null;
  comfyui_workflow_path?: string | null;
  comfyui_input_path_placeholder?: string | null;
  output_extension?: string | null;
  output_mime_type?: string | null;
  output_node_id?: string | null;
  parameters?: string | null;
  default_values?: string | null;
  credits_cost?: number | null;
  processing_time_estimate?: number | null;
  popularity_score?: number | null;
  sort_order?: number | null;
  is_active?: boolean;
  is_premium?: boolean;
  is_new?: boolean;
  ai_model_id?: number | null;
  ai_model?: {
    id?: number;
    name?: string;
  } | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type AdminEffectPayload = Partial<AdminEffect>;

export type AdminEffectsIndexData = {
  items: AdminEffect[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order: string;
  search: string | null;
  filters: unknown[];
};

type FilterValue = {
  field: string;
  operator: string;
  value: string | string[];
};

function appendFilterParams(params: URLSearchParams, filters?: FilterValue[]) {
  if (!filters) return;
  filters.forEach((filter) => {
    const operator = filter.operator || "=";
    const key = operator === "=" ? filter.field : `${filter.field}:${operator}`;
    const rawValue = Array.isArray(filter.value) ? filter.value.join(",") : filter.value;
    const value = typeof rawValue === "string" ? rawValue.trim() : rawValue;
    if (value === "" || value === null || value === undefined) {
      if (["isnull", "notnull", "doesnthave"].includes(operator)) {
        params.append(key, "1");
      }
      return;
    }
    params.append(key, String(value));
  });
}

export async function getAdminEffects(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<AdminEffectsIndexData> {
  const query = new URLSearchParams({
    page: String(params.page ?? 1),
    perPage: String(params.perPage ?? 20),
  });

  if (params.search) {
    query.set("search", params.search);
  }

  if (params.order) {
    query.set("order", params.order);
  }

  appendFilterParams(query, params.filters);

  return apiRequest<AdminEffectsIndexData>(`/admin/effects?${query.toString()}`, { method: "GET" });
}

export function createAdminEffect(payload: AdminEffectPayload): Promise<AdminEffect> {
  return apiPost<AdminEffect>("/admin/effects", payload);
}

export function updateAdminEffect(id: number, payload: AdminEffectPayload): Promise<AdminEffect> {
  return apiRequest<AdminEffect>(`/admin/effects/${id}`, { method: "PATCH", body: payload });
}

export function deleteAdminEffect(id: number): Promise<void> {
  return apiRequest<void>(`/admin/effects/${id}`, { method: "DELETE" });
}

export function initEffectAssetUpload(payload: EffectUploadInitRequest): Promise<EffectUploadInitData> {
  return apiPost<EffectUploadInitData>("/admin/effects/uploads", payload);
}

