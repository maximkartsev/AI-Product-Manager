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
  is_active?: boolean;
  publication_status?: "development" | "published" | null;
};

export type GalleryEffect = {
  id: number;
  slug: string;
  name: string;
  description?: string | null;
  type?: string | null;
  is_premium?: boolean;
  credits_cost?: number | null;
  is_active?: boolean;
  publication_status?: "development" | "published" | null;
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
  workload_kind?: "image" | "video" | null;
  work_units_property_key?: string | null;
  slo_p95_wait_seconds?: number | null;
  slo_video_seconds_per_processing_second_p95?: number | null;
  partner_cost_per_work_unit?: number | null;
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
  publication_status?: "development" | "published" | "disabled" | null;
  published_revision_id?: number | null;
  prod_execution_environment_id?: number | null;
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

export type AdminEffectRevision = {
  id: number;
  effect_id: number;
  workflow_id?: number | null;
  category_id?: number | null;
  publication_status?: "development" | "published" | "disabled" | null;
  property_overrides?: Record<string, unknown> | null;
  snapshot_json?: Record<string, unknown> | null;
  recommended_execution_environment_id?: number | null;
  created_by_user_id?: number | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioWorkflowRevision = {
  id: number;
  workflow_id: number;
  comfyui_workflow_path?: string | null;
  snapshot_json?: Record<string, unknown> | null;
  created_by_user_id?: number | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioWorkflowJsonData = {
  workflow_id: number;
  comfyui_workflow_path?: string | null;
  workflow_json: Record<string, unknown>;
  workflow_revision?: StudioWorkflowRevision;
};

export type StudioWorkflowCloneData = {
  workflow: AdminWorkflow;
  workflow_revision: StudioWorkflowRevision;
};

export type StudioEffectCloneMode = "effect_only" | "effect_and_workflow";

export type StudioEffectCloneData = {
  effect: AdminEffect;
  effect_revision: AdminEffectRevision;
  workflow?: AdminWorkflow;
};

export type StudioWorkflowAnalyzeResult = {
  properties: Array<{
    key: string;
    name?: string | null;
    type: "text" | "image" | "audio" | "video";
    required?: boolean;
    placeholder?: string | null;
    user_configurable?: boolean;
  }>;
  primary_input?: {
    node_id?: string | null;
    key?: string | null;
    type?: "text" | "image" | "audio" | "video" | null;
  } | null;
  output?: {
    node_id?: string | null;
    mime_type?: string | null;
    extension?: string | null;
  } | null;
  placeholder_insertions: Array<{
    json_pointer: string;
    placeholder: string;
    reason?: string | null;
  }>;
  autoscaling_hints?: {
    workload_kind?: "image" | "video" | null;
    work_units_property_key?: string | null;
    slo_p95_wait_seconds?: number | null;
    slo_video_seconds_per_processing_second_p95?: number | null;
  } | null;
};

export type StudioWorkflowAnalyzeJob = {
  id: number;
  workflow_id?: number | null;
  status: "pending" | "running" | "completed" | "failed";
  analyzer_prompt_version?: string | null;
  analyzer_schema_version?: string | null;
  requested_output_kind?: "image" | "video" | "audio" | null;
  input_json?: Record<string, unknown> | null;
  result_json?: StudioWorkflowAnalyzeResult | null;
  error_message?: string | null;
  created_by_user_id?: number | null;
  completed_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioExecutionEnvironment = {
  id: number;
  name: string;
  kind?: string | null;
  stage?: string | null;
  fleet_slug?: string | null;
  dev_node_id?: number | null;
  configuration_json?: Record<string, unknown> | null;
  is_active?: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioListResponse<T> = {
  items: T[];
};

export type StudioTestInputSet = {
  id: number;
  name: string;
  description?: string | null;
  input_json?: Record<string, unknown> | unknown[] | null;
  created_by_user_id?: number | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioEffectTestRun = {
  id: number;
  effect_id?: number | null;
  effect_revision_id?: number | null;
  execution_environment_id?: number | null;
  run_mode?: string | null;
  target_count?: number | null;
  status?: string | null;
  metrics_json?: Record<string, unknown> | unknown[] | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioLoadTestRun = {
  id: number;
  load_test_scenario_id?: number | null;
  execution_environment_id?: number | null;
  effect_revision_id?: number | null;
  experiment_variant_id?: number | null;
  status?: string | null;
  achieved_rpm?: number | null;
  achieved_rps?: number | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioExperimentVariant = {
  id: number;
  name: string;
  description?: string | null;
  target_environment_kind?: string | null;
  fleet_config_intent_json?: Record<string, unknown> | unknown[] | null;
  constraints_json?: Record<string, unknown> | unknown[] | null;
  is_active?: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioFleetConfigSnapshot = {
  id: number;
  execution_environment_id?: number | null;
  experiment_variant_id?: number | null;
  snapshot_scope?: string | null;
  config_json?: Record<string, unknown> | unknown[] | null;
  composition_json?: Record<string, unknown> | unknown[] | null;
  captured_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioProductionFleetSnapshot = {
  id: number;
  execution_environment_id?: number | null;
  fleet_slug?: string | null;
  stage?: string | null;
  queue_depth?: number | null;
  queue_units?: number | null;
  p95_queue_wait_seconds?: number | null;
  p95_processing_seconds?: number | null;
  interruptions_count?: number | null;
  rebalance_recommendations_count?: number | null;
  spot_discount_estimate?: number | null;
  captured_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type StudioRunArtifact = {
  id: number;
  effect_test_run_id?: number | null;
  load_test_run_id?: number | null;
  artifact_type?: string | null;
  storage_disk?: string | null;
  storage_path?: string | null;
  metadata_json?: Record<string, unknown> | unknown[] | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type AdminEffectStressTestPayload = {
  count: number;
  input_file_id: number;
  input_payload?: Record<string, unknown> | null;
  execute_on_production_fleet?: boolean;
};

export type AdminEffectStressTestResult = {
  queued_count: number;
  video_ids?: number[];
  job_ids?: number[];
};

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

export function getAdminEffectRevisions(effectId: number): Promise<StudioListResponse<AdminEffectRevision>> {
  return apiGet<StudioListResponse<AdminEffectRevision>>(`/admin/effects/${effectId}/revisions`);
}

export function createAdminEffectRevision(effectId: number): Promise<AdminEffectRevision> {
  return apiPost<AdminEffectRevision>(`/admin/effects/${effectId}/revisions`, {});
}

export function analyzeStudioWorkflow(payload: {
  workflow_id?: number;
  workflow_json?: Record<string, unknown>;
  requested_output_kind?: "image" | "video" | "audio";
  example_io_description?: string;
}): Promise<StudioWorkflowAnalyzeJob> {
  return apiPost<StudioWorkflowAnalyzeJob>("/admin/studio/workflow-analyze", payload);
}

export function getStudioWorkflowRevisions(workflowId: number): Promise<StudioListResponse<StudioWorkflowRevision>> {
  return apiGet<StudioListResponse<StudioWorkflowRevision>>(`/admin/studio/workflows/${workflowId}/revisions`);
}

export function createStudioWorkflowRevision(workflowId: number): Promise<StudioWorkflowRevision> {
  return apiPost<StudioWorkflowRevision>(`/admin/studio/workflows/${workflowId}/revisions`, {});
}

export function getStudioWorkflowJson(workflowId: number): Promise<StudioWorkflowJsonData> {
  return apiGet<StudioWorkflowJsonData>(`/admin/studio/workflows/${workflowId}/json`);
}

export function updateStudioWorkflowJson(
  workflowId: number,
  workflow_json: Record<string, unknown>,
): Promise<StudioWorkflowJsonData> {
  return apiRequest<StudioWorkflowJsonData>(`/admin/studio/workflows/${workflowId}/json`, {
    method: "PUT",
    body: { workflow_json },
  });
}

export function cloneStudioWorkflow(workflowId: number): Promise<StudioWorkflowCloneData> {
  return apiPost<StudioWorkflowCloneData>(`/admin/studio/workflows/${workflowId}/clone`, {});
}

export function cloneStudioEffect(effectId: number, mode: StudioEffectCloneMode): Promise<StudioEffectCloneData> {
  return apiPost<StudioEffectCloneData>(`/admin/studio/effects/${effectId}/clone`, { mode });
}

export function publishEffect(
  effectId: number,
  revisionId: number,
  prodExecutionEnvironmentId: number,
): Promise<AdminEffect> {
  return apiPost<AdminEffect>(`/admin/effects/${effectId}/publish`, {
    revision_id: revisionId,
    prod_execution_environment_id: prodExecutionEnvironmentId,
  });
}

export function unpublishEffect(effectId: number): Promise<AdminEffect> {
  return apiPost<AdminEffect>(`/admin/effects/${effectId}/unpublish`, {});
}

export function getStudioExecutionEnvironments(params: {
  kind?: string;
  stage?: string;
  is_active?: boolean;
} = {}): Promise<StudioListResponse<StudioExecutionEnvironment>> {
  const query: Query = {
    kind: params.kind ?? undefined,
    stage: params.stage ?? undefined,
    is_active: typeof params.is_active === "boolean" ? Number(params.is_active) : undefined,
  };
  return apiGet<StudioListResponse<StudioExecutionEnvironment>>("/admin/studio/execution-environments", query);
}

export function getStudioTestInputSets(): Promise<StudioListResponse<StudioTestInputSet>> {
  return apiGet<StudioListResponse<StudioTestInputSet>>("/admin/studio/test-input-sets");
}

export function createStudioTestInputSet(
  payload: Pick<StudioTestInputSet, "name" | "description" | "input_json">,
): Promise<StudioTestInputSet> {
  return apiPost<StudioTestInputSet>("/admin/studio/test-input-sets", payload);
}

export function getStudioEffectTestRuns(params: { status?: string; run_mode?: string } = {}): Promise<StudioListResponse<StudioEffectTestRun>> {
  const query: Query = {
    status: params.status ?? undefined,
    run_mode: params.run_mode ?? undefined,
  };
  return apiGet<StudioListResponse<StudioEffectTestRun>>("/admin/studio/effect-test-runs", query);
}

export function createStudioEffectTestRun(payload: Record<string, unknown>): Promise<StudioEffectTestRun> {
  return apiPost<StudioEffectTestRun>("/admin/studio/effect-test-runs", payload);
}

export function getStudioLoadTestRuns(params: { status?: string } = {}): Promise<StudioListResponse<StudioLoadTestRun>> {
  const query: Query = {
    status: params.status ?? undefined,
  };
  return apiGet<StudioListResponse<StudioLoadTestRun>>("/admin/studio/load-test-runs", query);
}

export function createStudioLoadTestRun(payload: Record<string, unknown>): Promise<StudioLoadTestRun> {
  return apiPost<StudioLoadTestRun>("/admin/studio/load-test-runs", payload);
}

export function getStudioExperimentVariants(params: { is_active?: boolean } = {}): Promise<StudioListResponse<StudioExperimentVariant>> {
  const query: Query = {
    is_active: typeof params.is_active === "boolean" ? Number(params.is_active) : undefined,
  };
  return apiGet<StudioListResponse<StudioExperimentVariant>>("/admin/studio/experiment-variants", query);
}

export function createStudioExperimentVariant(payload: Record<string, unknown>): Promise<StudioExperimentVariant> {
  return apiPost<StudioExperimentVariant>("/admin/studio/experiment-variants", payload);
}

export function updateStudioExperimentVariant(id: number, payload: Record<string, unknown>): Promise<StudioExperimentVariant> {
  return apiRequest<StudioExperimentVariant>(`/admin/studio/experiment-variants/${id}`, { method: "PATCH", body: payload });
}

export function getStudioFleetConfigSnapshots(params: { snapshot_scope?: string } = {}): Promise<StudioListResponse<StudioFleetConfigSnapshot>> {
  const query: Query = {
    snapshot_scope: params.snapshot_scope ?? undefined,
  };
  return apiGet<StudioListResponse<StudioFleetConfigSnapshot>>("/admin/studio/fleet-config-snapshots", query);
}

export function createStudioFleetConfigSnapshot(payload: Record<string, unknown>): Promise<StudioFleetConfigSnapshot> {
  return apiPost<StudioFleetConfigSnapshot>("/admin/studio/fleet-config-snapshots", payload);
}

export function getStudioProductionFleetSnapshots(params: { stage?: string } = {}): Promise<StudioListResponse<StudioProductionFleetSnapshot>> {
  const query: Query = {
    stage: params.stage ?? undefined,
  };
  return apiGet<StudioListResponse<StudioProductionFleetSnapshot>>("/admin/studio/production-fleet-snapshots", query);
}

export function createStudioProductionFleetSnapshot(payload: Record<string, unknown>): Promise<StudioProductionFleetSnapshot> {
  return apiPost<StudioProductionFleetSnapshot>("/admin/studio/production-fleet-snapshots", payload);
}

export function getStudioRunArtifacts(params: { artifact_type?: string } = {}): Promise<StudioListResponse<StudioRunArtifact>> {
  const query: Query = {
    artifact_type: params.artifact_type ?? undefined,
  };
  return apiGet<StudioListResponse<StudioRunArtifact>>("/admin/studio/run-artifacts", query);
}

export function createStudioRunArtifact(payload: Record<string, unknown>): Promise<StudioRunArtifact> {
  return apiPost<StudioRunArtifact>("/admin/studio/run-artifacts", payload);
}

export function deleteAdminEffect(id: number): Promise<void> {
  return apiRequest<void>(`/admin/effects/${id}`, { method: "DELETE" });
}

export function stressTestEffect(
  effectId: number,
  payload: AdminEffectStressTestPayload,
): Promise<AdminEffectStressTestResult> {
  return apiPost<AdminEffectStressTestResult>(`/admin/effects/${effectId}/stress-test`, payload);
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

export function getAdminUiSettings(): Promise<Record<string, unknown>> {
  return apiGet<Record<string, unknown>>("/admin/ui-settings");
}

export function updateAdminUiSettings(settings: Record<string, unknown>): Promise<Record<string, unknown>> {
  return apiRequest<Record<string, unknown>>("/admin/ui-settings", {
    method: "PUT",
    body: { settings },
  });
}

export function resetAdminUiSettings(): Promise<void> {
  return apiRequest<void>("/admin/ui-settings", { method: "DELETE" });
}

// ---- Admin Economics Settings

export type EconomicsSettings = {
  token_usd_rate: number;
  spot_multiplier?: number | null;
  instance_type_rates: Record<string, number>;
  defaults_applied?: boolean;
};

export type EconomicsSettingsPayload = {
  token_usd_rate: number;
  spot_multiplier?: number | null;
  instance_type_rates: Record<string, number>;
};

export function getEconomicsSettings(): Promise<EconomicsSettings> {
  return apiGet<EconomicsSettings>("/admin/economics/settings");
}

export function updateEconomicsSettings(payload: EconomicsSettingsPayload): Promise<EconomicsSettings> {
  return apiRequest<EconomicsSettings>("/admin/economics/settings", { method: "PUT", body: payload });
}

export type PartnerUsagePricingItem = {
  id: number;
  provider: string;
  nodeClassType: string;
  model?: string | null;
  usdPer1mInputTokens?: number | null;
  usdPer1mOutputTokens?: number | null;
  usdPer1mTotalTokens?: number | null;
  usdPerCredit?: number | null;
  firstSeenAt?: string | null;
  lastSeenAt?: string | null;
  sampleUiJson?: Record<string, unknown> | unknown[] | null;
  createdAt?: string | null;
  updatedAt?: string | null;
};

export type PartnerUsagePricingData = {
  items: PartnerUsagePricingItem[];
};

export type PartnerUsagePricingPayload = {
  items: Array<{
    provider: string;
    nodeClassType: string;
    model?: string | null;
    usdPer1mInputTokens?: number | null;
    usdPer1mOutputTokens?: number | null;
    usdPer1mTotalTokens?: number | null;
    usdPerCredit?: number | null;
  }>;
};

export function getPartnerUsagePricing(): Promise<PartnerUsagePricingData> {
  return apiGet<PartnerUsagePricingData>("/admin/economics/partner-pricing");
}

export function updatePartnerUsagePricing(payload: PartnerUsagePricingPayload): Promise<PartnerUsagePricingData> {
  return apiRequest<PartnerUsagePricingData>("/admin/economics/partner-pricing", { method: "PUT", body: payload });
}

export type PartnerUsageTotals = {
  eventsCount: number;
  inputTokens: number;
  outputTokens: number;
  totalTokens: number;
  credits: number;
  costUsdReported: number;
};

export type PartnerUsageByProviderNodeModel = {
  provider: string;
  nodeClassType: string;
  model?: string | null;
  eventsCount: number;
  inputTokens: number;
  outputTokens: number;
  totalTokens: number;
  credits: number;
  costUsdReported: number;
  lastSeenAt?: string | null;
};

export type PartnerUsageByEffect = {
  effectId: number;
  effectName: string;
  eventsCount: number;
  totalTokens: number;
  credits: number;
  costUsdReported: number;
};

export type PartnerUsageByWorkflow = {
  workflowId: number;
  workflowName: string;
  eventsCount: number;
  totalTokens: number;
  credits: number;
  costUsdReported: number;
};

export type PartnerUsageByUser = {
  userId: number;
  userName: string;
  userEmail?: string | null;
  eventsCount: number;
  totalTokens: number;
  credits: number;
  costUsdReported: number;
};

export type PartnerUsageByWorker = {
  workerId: string;
  workerName: string;
  capacityType?: string | null;
  instanceType?: string | null;
  stage?: string | null;
  eventsCount: number;
  totalTokens: number;
  credits: number;
  costUsdReported: number;
};

export type PartnerUsageAnalyticsData = {
  totals: PartnerUsageTotals;
  byProviderNodeModel: PartnerUsageByProviderNodeModel[];
  byEffect: PartnerUsageByEffect[];
  byWorkflow: PartnerUsageByWorkflow[];
  byUser: PartnerUsageByUser[];
  byWorker: PartnerUsageByWorker[];
};

export function getPartnerUsageAnalytics(params: {
  from?: string;
  to?: string;
  effect_id?: number;
  workflow_id?: number;
  user_id?: number;
  worker_id?: string;
  provider?: string;
} = {}): Promise<PartnerUsageAnalyticsData> {
  const query: Query = {
    from: params.from ?? undefined,
    to: params.to ?? undefined,
    effect_id: params.effect_id ?? undefined,
    workflow_id: params.workflow_id ?? undefined,
    user_id: params.user_id ?? undefined,
    worker_id: params.worker_id ?? undefined,
    provider: params.provider ?? undefined,
  };
  return apiGet<PartnerUsageAnalyticsData>("/admin/economics/partner-usage", query);
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

// ---- Unit Economics
export type UnitEconomicsByEffect = {
  effectId: number;
  effectName: string;
  workflowId?: number | null;
  workflowName?: string | null;
  workloadKind?: "image" | "video" | null;
  workUnitKind?: string | null;
  totalTokens: number;
  totalJobs: number;
  totalProcessingSeconds: number;
  totalQueueWaitSeconds: number;
  totalWorkUnits: number;
  avgProcessingSeconds?: number | null;
  avgProcessingSecondsPerUnit?: number | null;
  avgTokensPerJob?: number | null;
  avgTokensPerWorkUnit?: number | null;
  partnerCostPerWorkUnit?: number | null;
  partnerCostUsd?: number | null;
  partnerUsageInputTokens?: number | null;
  partnerUsageOutputTokens?: number | null;
  partnerUsageTotalTokens?: number | null;
  partnerUsageCredits?: number | null;
  partnerUsageCostUsd?: number | null;
  partnerUsageCostUsdReported?: number | null;
  partnerCostUsdTotal?: number | null;
  fleetSlugs?: string[] | null;
  fleetInstanceTypes?: string[] | null;
};

export type UnitEconomicsTotals = {
  totalTokens: number;
  totalJobs: number;
  totalProcessingSeconds: number;
  totalWorkUnits: number;
  totalPartnerCostUsd?: number | null;
  totalPartnerUsageInputTokens?: number | null;
  totalPartnerUsageOutputTokens?: number | null;
  totalPartnerUsageTokens?: number | null;
  totalPartnerUsageCredits?: number | null;
  totalPartnerUsageCostUsd?: number | null;
  totalPartnerUsageCostUsdReported?: number | null;
  totalPartnerCostUsdCombined?: number | null;
};

export type UnitEconomicsData = {
  byEffect: UnitEconomicsByEffect[];
  totals: UnitEconomicsTotals;
};

export function getUnitEconomicsAnalytics(params: {
  from?: string;
  to?: string;
} = {}): Promise<UnitEconomicsData> {
  const query: Query = {
    from: params.from ?? undefined,
    to: params.to ?? undefined,
  };
  return apiGet<UnitEconomicsData>("/admin/economics/unit-economics", query);
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
  spot_workers?: number | null;
  on_demand_workers?: number | null;
  unknown_workers?: number | null;
  busy_seconds?: number | null;
  running_seconds?: number | null;
  utilization?: number | null;
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


export function getWorkerAuditLogs(workerId: number, params: { page?: number; perPage?: number } = {}): Promise<AdminAuditLogsIndexData> {
  const query: Query = { page: params.page ?? 1, perPage: params.perPage ?? 20 };
  return apiGet<AdminAuditLogsIndexData>(`/admin/workers/${workerId}/audit-logs`, query);
}

// ---- Admin Workload

export type WorkloadWorkflowStats = {
  queued: number;
  processing: number;
  queue_units: number;
  active_workers: number;
  completed: number;
  failed: number;
  avg_duration_seconds: number | null;
  total_duration_seconds: number | null;
  p95_queue_wait_seconds: number | null;
  processing_seconds_per_unit_p95: number | null;
  estimated_wait_seconds_p95: number | null;
  slo_pressure: number | null;
  slo_p95_wait_seconds: number | null;
  slo_video_seconds_per_processing_second_p95: number | null;
  workload_kind: "image" | "video" | null;
  work_units_property_key: string | null;
  recommended_workers: number | null;
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

export function getWorkload(params?: {
  period?: string;
  stage?: "staging" | "production";
}): Promise<WorkloadData> {
  const query: Query = {
    period: params?.period ?? undefined,
    stage: params?.stage ?? undefined,
  };
  return apiGet<WorkloadData>("/admin/workload", query);
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

