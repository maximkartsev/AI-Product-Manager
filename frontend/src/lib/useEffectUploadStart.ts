import { useEffect, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ApiError, getAccessToken } from "@/lib/api";
import { savePendingUpload } from "@/lib/uploadPreviewStore";

type UploadState = { status: "idle" } | { status: "error"; message: string };

type UploadPromptContext = {
  positivePrompt?: string | null;
  negativePrompt?: string | null;
};

type UseEffectUploadStartArgs = {
  slug: string | null;
  autoUpload?: boolean;
  onError?: (message: string) => void;
};

type UseEffectUploadStartReturn = {
  fileInputRef: React.RefObject<HTMLInputElement | null>;
  startUpload: (slugOverride?: string | null, context?: UploadPromptContext | null) => {
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
  const pendingContextRef = useRef<UploadPromptContext | null>(null);
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

  function startUpload(slugOverride?: string | null, context?: UploadPromptContext | null) {
    const targetSlug = slugOverride ?? slug;
    if (!targetSlug) {
      return { ok: false, reason: "missing_slug" } as const;
    }
    pendingContextRef.current = context ?? null;
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
      const positive = typeof context?.positivePrompt === "string" ? context.positivePrompt.trim() : "";
      const negative = typeof context?.negativePrompt === "string" ? context.negativePrompt.trim() : "";
      if (positive || negative) {
        try {
          window.sessionStorage.setItem(
            `upload_ctx_${uploadId}`,
            JSON.stringify({
              ...(positive ? { positive_prompt: positive } : {}),
              ...(negative ? { negative_prompt: negative } : {}),
            }),
          );
        } catch {
          // ignore storage issues
        }
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
