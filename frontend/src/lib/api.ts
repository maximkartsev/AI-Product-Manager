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
  first_name?: string;
  last_name?: string;
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

export type EffectAssetUploadInitRequest = {
  effect_id: number;
  upload_id: string;
  property_key: string;
  kind: "image" | "video";
  mime_type: string;
  size: number;
  original_filename: string;
  file_hash?: string | null;
};

export type UserFile = {
  id: number;
  disk?: string | null;
  path?: string | null;
  url?: string | null;
  mime_type?: string | null;
  size?: number | null;
  original_filename?: string | null;
  created_at?: string | null;
  download_url?: string | null;
};

export type FilesIndexData = {
  items: UserFile[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
  filters?: unknown[];
};

export type FileUploadInitData = {
  file: UserFile;
  upload_url: string;
  upload_headers: Record<string, string | string[]>;
  expires_in: number;
};

export type VideoInputPayload = {
  positive_prompt?: string;
  negative_prompt?: string;
  [key: string]: unknown;
};

export type VideoCreateRequest = {
  effect_id: number;
  original_file_id: number;
  title?: string | null;
  input_payload?: VideoInputPayload | null;
};

export type VideoData = {
  id: number;
  status: string;
  effect_id: number;
  original_file_id: number | null;
  processed_file_id?: number | null;
  original_file_url?: string | null;
  title?: string | null;
  is_public?: boolean;
  processing_details?: Record<string, unknown> | null;
  input_payload?: VideoInputPayload | null;
  expires_at?: string | null;
  processed_file_url?: string | null;
  error?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  effect?: VideoEffectSummary | null;
};

export type VideoEffectSummary = {
  id: number;
  slug: string;
  name: string;
  description?: string | null;
  type?: string | null;
  is_premium?: boolean;
};

export type GalleryEffect = {
  id: number;
  slug: string;
  name: string;
  description?: string | null;
  type?: string | null;
  is_premium?: boolean;
  credits_cost?: number | null;
  configurable_properties?: ConfigurableProperty[] | null;
  category?: {
    id: number;
    slug: string;
    name: string;
    description?: string | null;
  } | null;
};

export type GalleryVideo = {
  id: number;
  title?: string | null;
  tags?: string[] | null;
  input_payload?: VideoInputPayload | null;
  created_at?: string | null;
  processed_file_url?: string | null;
  thumbnail_url?: string | null;
  effect?: GalleryEffect | null;
};

export type GalleryIndexData = {
  items: GalleryVideo[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
};

export type VideosIndexData = {
  items: VideoData[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
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
  error_message?: string | null;
  video_id?: number | null;
};

export type ConfigurableProperty = {
  key: string;
  name?: string | null;
  description?: string | null;
  type: "text" | "image" | "video";
  required?: boolean;
  default_value?: string | null;
};

export type ApiEffect = {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  category?: {
    id: number;
    name: string;
    slug: string;
    description?: string | null;
  } | null;
  type?: string | null;
  tags?: string[] | null;
  thumbnail_url?: string | null;
  preview_video_url?: string | null;
  credits_cost?: number | null;
  popularity_score?: number | null;
  is_new?: boolean | null;
  last_processing_time_seconds?: number | null;
  is_premium: boolean;
  is_active: boolean;
  configurable_properties?: ConfigurableProperty[] | null;
};

export type ApiCategory = {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  sort_order?: number | null;
};

export type CategoriesIndexData = {
  items: ApiCategory[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
};

export type EffectsIndexData = {
  items: ApiEffect[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
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

function notifyAuthChange(): void {
  if (typeof window === "undefined") return;
  window.dispatchEvent(new Event("auth:changed"));
}

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
  notifyAuthChange();
}

export function clearAccessToken(): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(TOKEN_STORAGE_KEY);
  notifyAuthChange();
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
  notifyAuthChange();
}

export function clearTenantDomain(): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(TENANT_DOMAIN_STORAGE_KEY);
  notifyAuthChange();
}

// ---- Request helpers

type Query = Record<string, string | number | boolean | null | undefined>;

function resolveApiBaseUrl(): string {
  const baseUrl = process.env.NEXT_PUBLIC_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL;
  if (!baseUrl) {
    if (typeof window !== "undefined") {
      return `${window.location.origin}/api`;
    }
    throw new ApiError({
      status: 0,
      message:
        "Missing NEXT_PUBLIC_API_BASE_URL (or NEXT_PUBLIC_API_URL). Set it to your backend API base (e.g. http://localhost:80/api). For AWS (single ALB), `/api` also works.",
    });
  }

  // Allow relative base (e.g. "/api") when frontend and backend share the same origin.
  if (baseUrl.startsWith("/")) {
    if (typeof window === "undefined") {
      throw new ApiError({
        status: 0,
        message:
          "NEXT_PUBLIC_API_BASE_URL is a relative path. This is supported in the browser but not in server-only contexts. Set it to an absolute URL for server usage.",
      });
    }
    return `${window.location.origin}${baseUrl}`.replace(/\/$/, "");
  }

  return baseUrl.replace(/\/$/, "");
}

function isLoopbackHost(hostname: string): boolean {
  if (!hostname) return false;
  if (hostname === "localhost" || hostname === "127.0.0.1" || hostname === "::1") return true;
  return hostname.endsWith(".localhost");
}

function getEffectiveApiBaseUrl(): string {
  const base = resolveApiBaseUrl();
  const tenantDomain = getTenantDomain();
  if (!tenantDomain) return base;

  try {
    const url = new URL(base);
    const tenantIsLocalhost =
      tenantDomain === "localhost" || tenantDomain.endsWith(".localhost");
    if (tenantIsLocalhost && !isLoopbackHost(url.hostname)) {
      return base;
    }
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

// ---- Google OAuth types + functions

export type GoogleAuthRedirectData = { url: string };

export type GoogleAuthCallbackData = {
  type: "signin" | "signup";
  redirect_url: string;
  user: { id: number; name: string; email: string };
  access_token: string;
  token_type: string;
  tenant?: TenantInfo;
};

export function getGoogleSignInUrl(): Promise<GoogleAuthRedirectData> {
  return apiGet<GoogleAuthRedirectData>("/auth/google/signin");
}

export function handleGoogleSignInCallback(
  code: string,
  state?: string,
): Promise<GoogleAuthCallbackData> {
  const query: Query = { code, state };
  return apiGet<GoogleAuthCallbackData>("/auth/google/signin/callback", query);
}

export function getGoogleSignUpUrl(): Promise<GoogleAuthRedirectData> {
  return apiGet<GoogleAuthRedirectData>("/auth/google/signup");
}

export function handleGoogleSignUpCallback(
  code: string,
  state?: string,
): Promise<GoogleAuthCallbackData> {
  const query: Query = { code, state };
  return apiGet<GoogleAuthCallbackData>("/auth/google/signup/callback", query);
}

// ---- TikTok OAuth types + functions

export type TikTokAuthRedirectData = { url: string };

export type TikTokAuthCallbackData = {
  type: "signin" | "signup";
  redirect_url: string;
  user: { id: number; name: string; email: string };
  access_token: string;
  token_type: string;
  tenant?: TenantInfo;
};

export function getTikTokSignInUrl(): Promise<TikTokAuthRedirectData> {
  return apiGet<TikTokAuthRedirectData>("/auth/tiktok/signin");
}

export function handleTikTokSignInCallback(
  code: string,
  state?: string,
): Promise<TikTokAuthCallbackData> {
  const query: Query = { code, state };
  return apiGet<TikTokAuthCallbackData>("/auth/tiktok/signin/callback", query);
}

export function getTikTokSignUpUrl(): Promise<TikTokAuthRedirectData> {
  return apiGet<TikTokAuthRedirectData>("/auth/tiktok/signup");
}

export function handleTikTokSignUpCallback(
  code: string,
  state?: string,
): Promise<TikTokAuthCallbackData> {
  const query: Query = { code, state };
  return apiGet<TikTokAuthCallbackData>("/auth/tiktok/signup/callback", query);
}

// ---- Apple OAuth

export type AppleAuthRedirectData = { url: string };

export function getAppleSignInUrl(): Promise<AppleAuthRedirectData> {
  return apiGet<AppleAuthRedirectData>("/auth/apple/signin");
}

export function getAppleSignUpUrl(): Promise<AppleAuthRedirectData> {
  return apiGet<AppleAuthRedirectData>("/auth/apple/signup");
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

export function listFiles(params?: {
  kind?: "image" | "video";
  page?: number;
  perPage?: number;
  order?: string | null;
  search?: string | null;
}): Promise<FilesIndexData> {
  const query: Query = {
    kind: params?.kind ?? undefined,
    page: params?.page ?? 1,
    perPage: params?.perPage ?? 20,
    order: params?.order ?? undefined,
    search: params?.search ?? undefined,
  };
  return apiGet<FilesIndexData>("/files", query);
}

export function initEffectAssetUpload(payload: EffectAssetUploadInitRequest): Promise<FileUploadInitData> {
  return apiPost<FileUploadInitData>("/files/uploads", payload);
}

export function initVideoUpload(payload: UploadInitRequest): Promise<UploadInitData> {
  return apiPost<UploadInitData>("/videos/uploads", payload);
}

export function createVideo(payload: VideoCreateRequest): Promise<VideoData> {
  return apiPost<VideoData>("/videos", payload);
}

export function getVideo(id: number): Promise<VideoData> {
  return apiGet<VideoData>(`/videos/${id}`);
}

export function getVideosIndex(params?: {
  page?: number;
  perPage?: number;
  order?: string | null;
}): Promise<VideosIndexData> {
  const query: Query = {
    page: params?.page ?? 1,
    perPage: params?.perPage ?? 20,
    order: params?.order ?? undefined,
  };
  return apiGet<VideosIndexData>("/videos", query);
}

export function publishVideo(videoId: number): Promise<GalleryVideo> {
  return apiPost<GalleryVideo>(`/videos/${videoId}/publish`);
}

export function unpublishVideo(videoId: number): Promise<VideoData> {
  return apiPost<VideoData>(`/videos/${videoId}/unpublish`);
}

export function updateVideo(id: number, payload: { title?: string | null }): Promise<VideoData> {
  return apiRequest<VideoData>(`/videos/${id}`, { method: "PATCH", body: payload });
}

export function deleteVideo(id: number): Promise<void> {
  return apiRequest<void>(`/videos/${id}`, { method: "DELETE" });
}

export function submitAiJob(payload: AiJobRequest): Promise<AiJobData> {
  return apiPost<AiJobData>("/ai-jobs", payload);
}

export function getEffectsIndex(params?: {
  page?: number;
  perPage?: number;
  order?: string | null;
  search?: string | null;
  category?: string | null;
}): Promise<EffectsIndexData> {
  const query: Query = {
    page: params?.page ?? 1,
    perPage: params?.perPage ?? 20,
    order: params?.order ?? undefined,
    search: params?.search ?? undefined,
    category: params?.category ?? undefined,
  };
  return apiGet<EffectsIndexData>("/effects", query);
}

export function getEffects(): Promise<ApiEffect[]> {
  return getEffectsIndex().then((data) => data.items ?? []);
}

export function getEffect(slug: string): Promise<ApiEffect> {
  return apiGet<ApiEffect>(`/effects/${encodeURIComponent(slug)}`);
}

export function getPublicGallery(params?: {
  page?: number;
  perPage?: number;
  search?: string | null;
  order?: string | null;
  filters?: FilterValue[];
}): Promise<GalleryIndexData> {
  const query = new URLSearchParams({
    page: String(params?.page ?? 1),
    perPage: String(params?.perPage ?? 20),
  });

  if (params?.search) {
    query.set("search", params.search);
  }

  if (params?.order) {
    query.set("order", params.order);
  }

  appendFilterParams(query, params?.filters);

  return apiRequest<GalleryIndexData>(`/gallery?${query.toString()}`, { method: "GET" });
}

export function getCategories(params?: {
  page?: number;
  perPage?: number;
  order?: string | null;
  search?: string | null;
}): Promise<CategoriesIndexData> {
  const query: Query = {
    page: params?.page ?? 1,
    perPage: params?.perPage ?? 5,
    order: params?.order ?? undefined,
    search: params?.search ?? undefined,
  };
  return apiGet<CategoriesIndexData>("/categories", query);
}

export function getCategory(slugOrId: string | number): Promise<ApiCategory> {
  return apiGet<ApiCategory>(`/categories/${encodeURIComponent(slugOrId)}`);
}

export function getPublicGalleryItem(id: number): Promise<GalleryVideo> {
  return apiGet<GalleryVideo>(`/gallery/${id}`);
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
  foreignKey?: { field: string; relation: string };
};

export async function getAvailableColumns(entityClass: string): Promise<ColumnConfig[]> {
  const data = await apiRequest<{ columns: ColumnConfig[] }>(`/columns?class=${encodeURIComponent(entityClass)}`, {
    method: "GET",
  });
  return data.columns ?? [];
}

export type FilterOption = { id: string | number; name: string };

export async function getFilterOptions(
  entityClass: string,
  field: string,
  search?: string,
): Promise<FilterOption[]> {
  const query: Query = {
    class: entityClass,
    field,
    search: search ?? undefined,
  };
  const data = await apiRequest<{ options: FilterOption[] }>("/filter-options", { method: "GET", query });
  return data.options ?? [];
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

// ---- Workflow types

export type WorkflowProperty = {
  key: string;
  name: string;
  description?: string;
  type: "text" | "image" | "video";
  placeholder: string;
  default_value?: string | null;
  default_value_hash?: string | null;
  required?: boolean;
  user_configurable?: boolean;
  is_primary_input?: boolean;
};

export type AdminWorkflow = {
  id: number;
  name?: string;
  slug?: string;
  description?: string | null;
  comfyui_workflow_path?: string | null;
  properties?: WorkflowProperty[] | null;
  output_node_id?: string | null;
  output_extension?: string | null;
  output_mime_type?: string | null;
  is_active?: boolean;
  fleets?: ComfyUiGpuFleet[];
  created_at?: string | null;
  updated_at?: string | null;
};

export type AdminWorkflowPayload = Partial<AdminWorkflow>;

export type AdminWorkflowFleetAssignments = {
  staging_fleet_id?: number | null;
  production_fleet_id?: number | null;
};

export type AdminWorkflowsIndexData = {
  items: AdminWorkflow[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order: string;
  search: string | null;
  filters: unknown[];
};

// ---- Worker types

export type AdminWorker = {
  id: number;
  worker_id?: string;
  display_name?: string | null;
  capabilities?: Record<string, unknown> | null;
  max_concurrency?: number;
  current_load?: number;
  last_seen_at?: string | null;
  is_draining?: boolean;
  is_approved?: boolean;
  last_ip?: string | null;
  registration_source?: string | null;
  workflows_count?: number;
  workflows?: AdminWorkflow[];
  recent_audit_logs?: WorkerAuditLog[];
  created_at?: string | null;
  updated_at?: string | null;
};

export type AdminWorkersIndexData = {
  items: AdminWorker[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order: string;
  search: string | null;
  filters: unknown[];
};

// ---- Audit Log types

export type WorkerAuditLog = {
  id: number;
  worker_id?: number | null;
  worker_identifier?: string | null;
  worker_display_name?: string | null;
  event: string;
  dispatch_id?: number | null;
  ip_address?: string | null;
  metadata?: Record<string, unknown> | null;
  created_at: string;
};

export type AdminAuditLogsIndexData = {
  items: WorkerAuditLog[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order: string;
  search: string | null;
  filters: unknown[];
};

export type AdminEffect = {
  id: number;
  name?: string;
  slug?: string;
  description?: string | null;
  category_id?: number | null;
  workflow_id?: number | null;
  property_overrides?: Record<string, string> | null;
  category?: { id: number; name?: string } | null;
  tags?: string[] | null;
  type?: string | null;
  thumbnail_url?: string | null;
  preview_video_url?: string | null;
  output_node_id?: string | null;
  credits_cost?: number | null;
  popularity_score?: number | null;
  is_active?: boolean;
  is_premium?: boolean;
  is_new?: boolean;
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

export type AdminCategory = {
  id: number;
  name?: string;
  slug?: string;
  description?: string | null;
  sort_order?: number | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type AdminCategoryPayload = Partial<AdminCategory>;

export type AdminCategoriesIndexData = {
  items: AdminCategory[];
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

export async function getAdminCategories(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<AdminCategoriesIndexData> {
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

  return apiRequest<AdminCategoriesIndexData>(`/admin/categories?${query.toString()}`, { method: "GET" });
}

export function createAdminCategory(payload: AdminCategoryPayload): Promise<AdminCategory> {
  return apiPost<AdminCategory>("/admin/categories", payload);
}

export function updateAdminCategory(id: number, payload: AdminCategoryPayload): Promise<AdminCategory> {
  return apiRequest<AdminCategory>(`/admin/categories/${id}`, { method: "PATCH", body: payload });
}

export function deleteAdminCategory(id: number): Promise<void> {
  return apiRequest<void>(`/admin/categories/${id}`, { method: "DELETE" });
}

export function initAdminEffectUpload(payload: EffectUploadInitRequest): Promise<EffectUploadInitData> {
  return apiPost<EffectUploadInitData>("/admin/effects/uploads", payload);
}

// ---- Admin UI Settings

export function getAdminUiSettings(): Promise<Record<string, any>> {
  return apiGet<Record<string, any>>("/admin/ui-settings");
}

export function updateAdminUiSettings(settings: Record<string, any>): Promise<Record<string, any>> {
  return apiRequest<Record<string, any>>("/admin/ui-settings", {
    method: "PUT",
    body: { settings },
  });
}

export function resetAdminUiSettings(): Promise<void> {
  return apiRequest<void>("/admin/ui-settings", { method: "DELETE" });
}

// ---- Admin Users

export type AdminUser = {
  id: number;
  name: string;
  email: string;
  is_admin: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};

export type AdminUserDetail = AdminUser & {
  tenant?: {
    id: string;
    domain?: string;
    db_pool?: string;
  } | null;
};

export type AdminUsersIndexData = {
  items: AdminUser[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order: string;
  search: string | null;
  filters: unknown[];
};

export type AdminPurchase = {
  id: number;
  user_id: number;
  total_amount: number;
  status: string;
  processed_at?: string | null;
  created_at?: string | null;
  payment?: {
    id: number;
    payment_gateway: string;
    amount: number;
    currency: string;
    status: string;
  } | null;
};

export type AdminPurchasesIndexData = {
  items: AdminPurchase[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
};

export type AdminTokenTransaction = {
  id: number;
  amount: number;
  type: string;
  description?: string | null;
  created_at?: string | null;
  job_id?: number | null;
};

export type AdminTokenData = {
  balance: number;
  items: AdminTokenTransaction[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
};

export async function getAdminUsers(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<AdminUsersIndexData> {
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

  return apiRequest<AdminUsersIndexData>(`/admin/users?${query.toString()}`, { method: "GET" });
}

export function getAdminUser(id: number): Promise<AdminUserDetail> {
  return apiGet<AdminUserDetail>(`/admin/users/${id}`);
}

export function getAdminUserPurchases(
  userId: number,
  params: { page?: number; perPage?: number } = {},
): Promise<AdminPurchasesIndexData> {
  const query: Query = {
    page: params.page ?? 1,
    perPage: params.perPage ?? 20,
  };
  return apiGet<AdminPurchasesIndexData>(`/admin/users/${userId}/purchases`, query);
}

export function getAdminUserTokens(
  userId: number,
  params: { page?: number; perPage?: number } = {},
): Promise<AdminTokenData> {
  const query: Query = {
    page: params.page ?? 1,
    perPage: params.perPage ?? 20,
  };
  return apiGet<AdminTokenData>(`/admin/users/${userId}/tokens`, query);
}

// ---- Admin Analytics

export type TokenSpendingTimeSeries = {
  bucket: string;
  totalTokens: number;
};

export type TokenSpendingByEffect = {
  effectId: number;
  effectName: string;
  totalTokens: number;
};

export type TokenSpendingData = {
  timeSeries: TokenSpendingTimeSeries[];
  byEffect: TokenSpendingByEffect[];
  totalTokens: number;
};

export function getTokenSpendingAnalytics(params: {
  from?: string;
  to?: string;
  granularity?: "day" | "week" | "month";
} = {}): Promise<TokenSpendingData> {
  const query: Query = {
    from: params.from ?? undefined,
    to: params.to ?? undefined,
    granularity: params.granularity ?? "day",
  };
  return apiGet<TokenSpendingData>("/admin/analytics/token-spending", query);
}

// ---- Admin Workflows

export async function getAdminWorkflows(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<AdminWorkflowsIndexData> {
  const query = new URLSearchParams({
    page: String(params.page ?? 1),
    perPage: String(params.perPage ?? 20),
  });
  if (params.search) query.set("search", params.search);
  if (params.order) query.set("order", params.order);
  appendFilterParams(query, params.filters);
  return apiRequest<AdminWorkflowsIndexData>(`/admin/workflows?${query.toString()}`, { method: "GET" });
}

export function createAdminWorkflow(payload: AdminWorkflowPayload): Promise<AdminWorkflow> {
  return apiPost<AdminWorkflow>("/admin/workflows", payload);
}

export function updateAdminWorkflow(id: number, payload: AdminWorkflowPayload): Promise<AdminWorkflow> {
  return apiRequest<AdminWorkflow>(`/admin/workflows/${id}`, { method: "PATCH", body: payload });
}

export function assignWorkflowFleets(id: number, payload: AdminWorkflowFleetAssignments): Promise<AdminWorkflow> {
  return apiRequest<AdminWorkflow>(`/admin/workflows/${id}/fleet-assignments`, { method: "PUT", body: payload });
}

export function deleteAdminWorkflow(id: number): Promise<void> {
  return apiRequest<void>(`/admin/workflows/${id}`, { method: "DELETE" });
}

export type WorkflowUploadInitRequest = {
  kind: "workflow_json" | "property_asset";
  workflow_id?: number;
  property_key?: string;
  mime_type: string;
  size: number;
  original_filename: string;
};

export type WorkflowUploadInitData = {
  path: string;
  upload_url: string;
  upload_headers: Record<string, string | string[]>;
  expires_in: number;
};

export function initWorkflowAssetUpload(payload: WorkflowUploadInitRequest): Promise<WorkflowUploadInitData> {
  return apiPost<WorkflowUploadInitData>("/admin/workflows/uploads", payload);
}

// ---- Admin ComfyUI Assets

export type ComfyUiAssetFile = {
  id: number;
  kind: string;
  original_filename: string;
  s3_key: string;
  content_type?: string | null;
  size_bytes?: number | null;
  sha256: string;
  notes?: string | null;
  uploaded_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type ComfyUiAssetBundle = {
  id: number;
  bundle_id: string;
  name?: string | null;
  s3_prefix: string;
  notes?: string | null;
  manifest?: Record<string, unknown> | null;
  created_by_user_id?: number | null;
  created_by_email?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type ComfyUiGpuFleet = {
  id: number;
  stage: string;
  slug: string;
  template_slug?: string | null;
  name: string;
  instance_types?: string[] | null;
  max_size: number;
  warmup_seconds?: number | null;
  backlog_target?: number | null;
  scale_to_zero_minutes?: number | null;
  ami_ssm_parameter?: string | null;
  active_bundle_id?: number | null;
  active_bundle_s3_prefix?: string | null;
  active_bundle?: {
    id: number;
    bundle_id: string;
    name?: string | null;
    s3_prefix: string;
  } | null;
  workflows?: AdminWorkflow[];
  pivot?: {
    stage?: string | null;
    assigned_at?: string | null;
    assigned_by_user_id?: number | null;
    assigned_by_email?: string | null;
  };
  created_at?: string | null;
  updated_at?: string | null;
};

export type ComfyUiFleetTemplate = {
  template_slug: string;
  display_name: string;
  allowed_instance_types: string[];
  max_size: number;
  warmup_seconds?: number | null;
  backlog_target?: number | null;
  scale_to_zero_minutes?: number | null;
};

export type ComfyUiAssetAuditLog = {
  id: number;
  bundle_id?: number | null;
  asset_file_id?: number | null;
  event: string;
  notes?: string | null;
  metadata?: Record<string, unknown> | null;
  artifact_s3_key?: string | null;
  artifact_download_url?: string | null;
  artifact_expires_in?: number | null;
  actor_user_id?: number | null;
  actor_email?: string | null;
  created_at?: string | null;
};

export type ComfyUiAssetFilesIndexData = {
  items: ComfyUiAssetFile[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
  filters?: FilterValue[];
};

export type ComfyUiAssetBundlesIndexData = {
  items: ComfyUiAssetBundle[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
  filters?: FilterValue[];
};

export type ComfyUiFleetsIndexData = {
  items: ComfyUiGpuFleet[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
  filters?: FilterValue[];
};

export type ComfyUiAssetCleanupCandidate = {
  id: number;
  bundle_id: string;
  s3_prefix: string;
  reason: "not_active_in_any_fleet" | string;
};

export type ComfyUiAssetFileCleanupCandidate = {
  id: number;
  kind: string;
  original_filename: string;
  s3_key: string;
  sha256: string;
  size_bytes?: number | null;
  reason: "unreferenced" | string;
};

export type ComfyUiAssetAuditLogsIndexData = {
  items: ComfyUiAssetAuditLog[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order?: string | null;
  search?: string | null;
  filters?: FilterValue[];
};

export type ComfyUiAssetUploadInitRequest = {
  kind: string;
  mime_type: string;
  size_bytes: number;
  original_filename: string;
  sha256: string;
  notes?: string | null;
};

export type ComfyUiAssetUploadInitData = {
  path: string;
  upload_url: string;
  upload_headers: Record<string, string | string[]>;
  expires_in: number;
  already_exists?: boolean;
};

export type ComfyUiAssetMultipartUploadInitData = {
  key: string;
  upload_id: string;
  part_size: number;
  part_urls: Array<{ part_number: number; url: string }>;
  expires_in: number;
  already_exists?: boolean;
};

export type ComfyUiAssetMultipartUploadCompleteRequest = {
  key: string;
  upload_id: string;
  parts: Array<{ part_number: number; etag: string }>;
};

export type ComfyUiAssetFileCreateRequest = {
  kind: string;
  original_filename: string;
  content_type?: string | null;
  size_bytes?: number | null;
  sha256: string;
  notes?: string | null;
};

export type ComfyUiAssetFileUpdateRequest = {
  original_filename?: string | null;
  notes?: string | null;
};

export type ComfyUiAssetBundleCreateRequest = {
  name: string;
  asset_file_ids: number[];
  asset_overrides?: Array<{
    asset_file_id: number;
    target_path?: string | null;
    action?: "copy" | "extract_zip" | "extract_tar_gz";
  }>;
  notes?: string | null;
};

export type ComfyUiFleetCreateRequest = {
  stage: "staging" | "production";
  slug: string;
  name: string;
  template_slug: string;
  instance_type: string;
};

export type ComfyUiFleetUpdateRequest = Partial<Omit<ComfyUiFleetCreateRequest, "stage" | "slug">>;

export type ComfyUiFleetAssignWorkflowsRequest = {
  workflow_ids: number[];
};

export type ComfyUiFleetActivateBundleRequest = {
  bundle_id: number;
  notes?: string | null;
};

export function initComfyUiAssetUpload(payload: ComfyUiAssetUploadInitRequest): Promise<ComfyUiAssetUploadInitData> {
  return apiPost<ComfyUiAssetUploadInitData>("/admin/comfyui-assets/uploads", payload);
}

export function initComfyUiAssetMultipartUpload(
  payload: ComfyUiAssetUploadInitRequest,
): Promise<ComfyUiAssetMultipartUploadInitData> {
  return apiPost<ComfyUiAssetMultipartUploadInitData>("/admin/comfyui-assets/uploads/multipart", payload);
}

export function completeComfyUiAssetMultipartUpload(
  payload: ComfyUiAssetMultipartUploadCompleteRequest,
): Promise<{ key: string }> {
  return apiPost<{ key: string }>("/admin/comfyui-assets/uploads/multipart/complete", payload);
}

export function abortComfyUiAssetMultipartUpload(payload: { key: string; upload_id: string }): Promise<void> {
  return apiPost<void>("/admin/comfyui-assets/uploads/multipart/abort", payload);
}

export function createComfyUiAssetFile(payload: ComfyUiAssetFileCreateRequest): Promise<ComfyUiAssetFile> {
  return apiPost<ComfyUiAssetFile>("/admin/comfyui-assets/files", payload);
}

export function updateComfyUiAssetFile(id: number, payload: ComfyUiAssetFileUpdateRequest): Promise<ComfyUiAssetFile> {
  return apiRequest<ComfyUiAssetFile>(`/admin/comfyui-assets/files/${id}`, { method: "PATCH", body: payload });
}

export async function getComfyUiAssetFiles(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<ComfyUiAssetFilesIndexData> {
  const query = new URLSearchParams({
    page: String(params.page ?? 1),
    perPage: String(params.perPage ?? 20),
  });
  if (params.search) query.set("search", params.search);
  if (params.order) query.set("order", params.order);
  appendFilterParams(query, params.filters);
  return apiRequest<ComfyUiAssetFilesIndexData>(`/admin/comfyui-assets/files?${query.toString()}`, { method: "GET" });
}

export async function getComfyUiAssetBundles(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<ComfyUiAssetBundlesIndexData> {
  const query = new URLSearchParams({
    page: String(params.page ?? 1),
    perPage: String(params.perPage ?? 20),
  });
  if (params.search) query.set("search", params.search);
  if (params.order) query.set("order", params.order);
  appendFilterParams(query, params.filters);
  return apiRequest<ComfyUiAssetBundlesIndexData>(`/admin/comfyui-assets/bundles?${query.toString()}`, { method: "GET" });
}

export async function getComfyUiCleanupCandidates(): Promise<{ items: ComfyUiAssetCleanupCandidate[]; totalItems: number }> {
  return apiGet("/admin/comfyui-assets/cleanup-candidates");
}

export async function getComfyUiAssetFileCleanupCandidates(): Promise<{ items: ComfyUiAssetFileCleanupCandidate[]; totalItems: number }> {
  return apiGet("/admin/comfyui-assets/cleanup-assets");
}

export function deleteComfyUiAssetBundle(id: number): Promise<void> {
  return apiRequest<void>(`/admin/comfyui-assets/bundles/${id}`, { method: "DELETE" });
}

export function deleteComfyUiAssetFile(id: number): Promise<void> {
  return apiRequest<void>(`/admin/comfyui-assets/files/${id}`, { method: "DELETE" });
}

export function createComfyUiAssetBundle(payload: ComfyUiAssetBundleCreateRequest): Promise<ComfyUiAssetBundle> {
  return apiPost<ComfyUiAssetBundle>("/admin/comfyui-assets/bundles", payload);
}

export function updateComfyUiAssetBundle(id: number, payload: { name?: string | null; notes?: string | null }): Promise<ComfyUiAssetBundle> {
  return apiRequest<ComfyUiAssetBundle>(`/admin/comfyui-assets/bundles/${id}`, { method: "PATCH", body: payload });
}

export function getComfyUiAssetBundleManifest(id: number): Promise<{
  bundle_id: string;
  manifest_key: string;
  download_url: string;
  expires_in: number;
}> {
  return apiGet(`/admin/comfyui-assets/bundles/${id}/manifest`);
}

export async function getComfyUiFleets(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<ComfyUiFleetsIndexData> {
  const query = new URLSearchParams({
    page: String(params.page ?? 1),
    perPage: String(params.perPage ?? 20),
  });
  if (params.search) query.set("search", params.search);
  if (params.order) query.set("order", params.order);
  appendFilterParams(query, params.filters);
  return apiRequest<ComfyUiFleetsIndexData>(`/admin/comfyui-fleets?${query.toString()}`, { method: "GET" });
}

export function getComfyUiFleetTemplates(): Promise<{ items: ComfyUiFleetTemplate[] }> {
  return apiGet("/admin/comfyui-fleets/templates");
}

export function createComfyUiFleet(payload: ComfyUiFleetCreateRequest): Promise<ComfyUiGpuFleet> {
  return apiPost<ComfyUiGpuFleet>("/admin/comfyui-fleets", payload);
}

export function updateComfyUiFleet(id: number, payload: ComfyUiFleetUpdateRequest): Promise<ComfyUiGpuFleet> {
  return apiRequest<ComfyUiGpuFleet>(`/admin/comfyui-fleets/${id}`, { method: "PATCH", body: payload });
}

export function assignComfyUiFleetWorkflows(id: number, payload: ComfyUiFleetAssignWorkflowsRequest): Promise<ComfyUiGpuFleet> {
  return apiRequest<ComfyUiGpuFleet>(`/admin/comfyui-fleets/${id}/workflows`, { method: "PUT", body: payload });
}

export function activateComfyUiFleetBundle(id: number, payload: ComfyUiFleetActivateBundleRequest): Promise<ComfyUiGpuFleet> {
  return apiPost<ComfyUiGpuFleet>(`/admin/comfyui-fleets/${id}/activate-bundle`, payload);
}

export async function getComfyUiAssetAuditLogs(params: {
  page?: number;
  perPage?: number;
  event?: string | string[];
  bundle_id?: number;
  from_date?: string;
  to_date?: string;
  order?: string;
} = {}): Promise<ComfyUiAssetAuditLogsIndexData> {
  const query: Query = {
    page: params.page ?? 1,
    perPage: params.perPage ?? 20,
    event: params.event ? (Array.isArray(params.event) ? params.event.join(",") : params.event) : undefined,
    bundle_id: params.bundle_id ?? undefined,
    from_date: params.from_date ?? undefined,
    to_date: params.to_date ?? undefined,
    order: params.order ?? undefined,
  };
  return apiGet<ComfyUiAssetAuditLogsIndexData>("/admin/comfyui-assets/audit-logs", query);
}

export async function exportComfyUiAssetAuditLogs(params: {
  event?: string | string[];
  bundle_id?: number;
  from_date?: string;
  to_date?: string;
} = {}) {
  const query: Query = {
    event: params.event ? (Array.isArray(params.event) ? params.event.join(",") : params.event) : undefined,
    bundle_id: params.bundle_id ?? undefined,
    from_date: params.from_date ?? undefined,
    to_date: params.to_date ?? undefined,
    format: "json",
  };
  return apiGet<{ items: ComfyUiAssetAuditLog[]; totalItems: number }>("/admin/comfyui-assets/audit-logs/export", query);
}

// ---- Admin Workers

export async function getAdminWorkers(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  filters?: FilterValue[];
} = {}): Promise<AdminWorkersIndexData> {
  const query = new URLSearchParams({
    page: String(params.page ?? 1),
    perPage: String(params.perPage ?? 20),
  });
  if (params.search) query.set("search", params.search);
  if (params.order) query.set("order", params.order);
  appendFilterParams(query, params.filters);
  return apiRequest<AdminWorkersIndexData>(`/admin/workers?${query.toString()}`, { method: "GET" });
}

export function getAdminWorker(id: number): Promise<AdminWorker> {
  return apiGet<AdminWorker>(`/admin/workers/${id}`);
}

export function updateAdminWorker(id: number, payload: { display_name?: string; is_draining?: boolean }): Promise<AdminWorker> {
  return apiRequest<AdminWorker>(`/admin/workers/${id}`, { method: "PATCH", body: payload });
}

export function approveWorker(id: number): Promise<AdminWorker> {
  return apiPost<AdminWorker>(`/admin/workers/${id}/approve`);
}

export function revokeWorker(id: number): Promise<AdminWorker> {
  return apiPost<AdminWorker>(`/admin/workers/${id}/revoke`);
}

export function rotateWorkerToken(id: number): Promise<{ token: string; message: string }> {
  return apiPost<{ token: string; message: string }>(`/admin/workers/${id}/rotate-token`);
}

export function assignWorkerWorkflows(id: number, workflowIds: number[]): Promise<AdminWorker> {
  return apiRequest<AdminWorker>(`/admin/workers/${id}/workflows`, { method: "PUT", body: { workflow_ids: workflowIds } });
}

export function getWorkerAuditLogs(workerId: number, params: { page?: number; perPage?: number } = {}): Promise<AdminAuditLogsIndexData> {
  const query: Query = { page: params.page ?? 1, perPage: params.perPage ?? 20 };
  return apiGet<AdminAuditLogsIndexData>(`/admin/workers/${workerId}/audit-logs`, query);
}

// ---- Admin Workload

export type WorkloadWorkflowStats = {
  queued: number;
  processing: number;
  completed: number;
  failed: number;
  avg_duration_seconds: number | null;
  total_duration_seconds: number | null;
};

export type WorkloadWorkflow = {
  id: number;
  name: string;
  slug: string;
  is_active: boolean;
  stats: WorkloadWorkflowStats;
  worker_ids: number[];
};

export type WorkloadWorker = {
  id: number;
  worker_id: string;
  display_name: string | null;
  is_approved: boolean;
  is_draining: boolean;
  current_load: number;
  max_concurrency: number;
  last_seen_at: string | null;
};

export type WorkloadData = {
  workflows: WorkloadWorkflow[];
  workers: WorkloadWorker[];
};

export function getWorkload(period?: string): Promise<WorkloadData> {
  const query: Query = { period: period ?? undefined };
  return apiGet<WorkloadData>("/admin/workload", query);
}

export function assignWorkflowWorkers(workflowId: number, workerIds: number[]): Promise<unknown> {
  return apiRequest(`/admin/workload/workflows/${workflowId}/workers`, {
    method: "PUT",
    body: { worker_ids: workerIds },
  });
}

// ---- Admin Audit Logs (global)

export async function getAdminAuditLogs(params: {
  page?: number;
  perPage?: number;
  search?: string;
  order?: string;
  event?: string;
  worker_id?: number;
  from_date?: string;
  to_date?: string;
  filters?: FilterValue[];
} = {}): Promise<AdminAuditLogsIndexData> {
  const query = new URLSearchParams({
    page: String(params.page ?? 1),
    perPage: String(params.perPage ?? 20),
  });
  if (params.search) query.set("search", params.search);
  if (params.order) query.set("order", params.order);
  if (params.event) query.set("event", params.event);
  if (params.worker_id) query.set("worker_id", String(params.worker_id));
  if (params.from_date) query.set("from_date", params.from_date);
  if (params.to_date) query.set("to_date", params.to_date);
  appendFilterParams(query, params.filters);
  return apiRequest<AdminAuditLogsIndexData>(`/admin/audit-logs?${query.toString()}`, { method: "GET" });
}

