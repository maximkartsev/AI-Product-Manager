import { useEffect, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ApiError, getAccessToken } from "@/lib/api";
import { savePendingUpload } from "@/lib/uploadPreviewStore";

type UploadState = { status: "idle" } | { status: "error"; message: string };

type UseEffectUploadStartArgs = {
  slug: string | null;
  autoUpload?: boolean;
  onError?: (message: string) => void;
};

type UseEffectUploadStartReturn = {
  fileInputRef: React.RefObject<HTMLInputElement>;
  startUpload: (slugOverride?: string | null) => void;
  onFileSelected: (event: React.ChangeEvent<HTMLInputElement>) => Promise<void>;
  authOpen: boolean;
  closeAuth: () => void;
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
  const [authOpen, setAuthOpen] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [pendingUpload, setPendingUpload] = useState(false);
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

  function closeAuth() {
    setAuthOpen(false);
    const nextToken = getAccessToken();
    setToken(nextToken);
    if (pendingUpload && nextToken) {
      window.setTimeout(() => fileInputRef.current?.click(), 0);
    }
    setPendingUpload(false);
  }

  function startUpload(slugOverride?: string | null) {
    const targetSlug = slugOverride ?? slug;
    if (!targetSlug) {
      return;
    }
    setPendingSlug(targetSlug);
    const nextToken = token ?? getAccessToken();
    if (!nextToken) {
      setPendingUpload(true);
      setAuthOpen(true);
      return;
    }
    if (!token) setToken(nextToken);
    fileInputRef.current?.click();
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
    authOpen,
    closeAuth,
    token,
    uploadState,
    clearUploadError,
  };
}
