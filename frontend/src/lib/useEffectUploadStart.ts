import { useEffect, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ApiError, getAccessToken } from "@/lib/api";
import type { PendingAssetsMap } from "@/lib/effectUploadTypes";
import { savePendingAssets, savePendingUpload } from "@/lib/uploadPreviewStore";

type UploadState = { status: "idle" } | { status: "error"; message: string };

type UploadContext = Record<string, unknown>;

type UseEffectUploadStartArgs = {
  slug: string | null;
  autoUpload?: boolean;
  onError?: (message: string) => void;
};

type UseEffectUploadStartReturn = {
  fileInputRef: React.RefObject<HTMLInputElement | null>;
  startUpload: (
    slugOverride?: string | null,
    context?: UploadContext | null,
    pendingAssets?: PendingAssetsMap | null,
  ) => {
    ok: boolean;
    reason?: "unauthenticated" | "missing_slug";
  };
  onFileSelected: (event: React.ChangeEvent<HTMLInputElement>) => Promise<void>;
  token: string | null;
  uploadState: UploadState;
  clearUploadError: () => void;
};

export default function useEffectUploadStart({
  slug,
  autoUpload = false,
  onError,
}: UseEffectUploadStartArgs): UseEffectUploadStartReturn {
  const router = useRouter();
  const searchParams = useSearchParams();
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const autoUploadRef = useRef(false);
  const pendingContextRef = useRef<UploadContext | null>(null);
  const pendingAssetsRef = useRef<PendingAssetsMap | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [pendingSlug, setPendingSlug] = useState<string | null>(slug ?? null);
  const [uploadState, setUploadState] = useState<UploadState>({ status: "idle" });

  useEffect(() => {
    setPendingSlug(slug ?? null);
  }, [slug]);

  useEffect(() => {
    const t = window.setTimeout(() => setToken(getAccessToken()), 0);
    return () => window.clearTimeout(t);
  }, []);

  useEffect(() => {
    if (!autoUpload) return;
    if (autoUploadRef.current) return;
    if (searchParams.get("upload") !== "1") return;
    autoUploadRef.current = true;
    startUpload(slug);
  }, [autoUpload, searchParams, slug]);

  function clearUploadError() {
    setUploadState({ status: "idle" });
  }

  function startUpload(
    slugOverride?: string | null,
    context?: UploadContext | null,
    pendingAssets?: PendingAssetsMap | null,
  ) {
    const targetSlug = slugOverride ?? slug;
    if (!targetSlug) {
      return { ok: false, reason: "missing_slug" } as const;
    }
    pendingContextRef.current = context ?? null;
    pendingAssetsRef.current = pendingAssets ?? null;
    setPendingSlug(targetSlug);
    const nextToken = token ?? getAccessToken();
    if (!nextToken) {
      return { ok: false, reason: "unauthenticated" } as const;
    }
    if (!token) setToken(nextToken);
    fileInputRef.current?.click();
    return { ok: true };
  }

  async function onFileSelected(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

    const nextToken = token ?? getAccessToken();
    if (!nextToken) {
      const message = "Sign in to upload a video.";
      setUploadState({ status: "error", message });
      onError?.(message);
      return;
    }

    const targetSlug = pendingSlug ?? slug;
    if (!targetSlug) return;

    try {
      const uploadId = `upload_${crypto.randomUUID?.() ?? `${Date.now()}_${Math.random().toString(36).slice(2, 8)}`}`;
      await savePendingUpload(uploadId, file);
      const context = pendingContextRef.current;
      pendingContextRef.current = null;
      const pendingAssets = pendingAssetsRef.current;
      pendingAssetsRef.current = null;
      if (context && typeof context === "object") {
        const entries = Object.entries(context).filter(([, val]) => val !== null && val !== undefined && val !== "");
        if (entries.length > 0) {
          try {
            window.sessionStorage.setItem(`upload_ctx_${uploadId}`, JSON.stringify(Object.fromEntries(entries)));
          } catch {
            // ignore storage issues
          }
        }
      }
      if (pendingAssets && Object.keys(pendingAssets).length > 0) {
        await savePendingAssets(uploadId, Object.values(pendingAssets));
      }
      clearUploadError();
      router.push(`/effects/${encodeURIComponent(targetSlug)}/processing?uploadId=${uploadId}`);
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unexpected error while preparing the upload.";
      setUploadState({ status: "error", message });
      onError?.(message);
    }
  }

  return {
    fileInputRef,
    startUpload,
    onFileSelected,
    token,
    uploadState,
    clearUploadError,
  };
}
