"use client";

import {
  ApiError,
  createVideo,
  getAccessToken,
  getEffect,
  getVideo,
  initVideoUpload,
  submitAiJob,
  type ApiEffect,
  type VideoData,
} from "@/lib/api";
import useAuthToken from "@/lib/useAuthToken";
import { deletePendingUpload, deletePreview, loadPendingUpload, loadPreview, savePreview } from "@/lib/uploadPreviewStore";
import ProcessingStepProcessing from "@/app/effects/[slug]/processing/ProcessingStepProcessing";
import ProcessingStepResult from "@/app/effects/[slug]/processing/ProcessingStepResult";
import ProcessingStepUpload from "@/app/effects/[slug]/processing/ProcessingStepUpload";
import { AlertTriangle, Film, Layers, Sparkles, UploadCloud, Wand2 } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState, type ComponentType } from "react";
import useUiGuards from "@/components/guards/useUiGuards";
import { getRequiredTokensFromError } from "@/lib/apiErrorTokens";

type LoadState =
  | { status: "loading" }
  | { status: "success"; data: ApiEffect }
  | { status: "not_found" }
  | { status: "error"; message: string; code?: number };

type VideoPollState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "ready"; data: VideoData }
  | { status: "error"; message: string; code?: number };

type StepStatus = "pending" | "running" | "done" | "error";

type ProcessingStep = {
  id: string;
  label: string;
  icon: ComponentType<{ className?: string }>;
};

const PROCESSING_STEPS: ProcessingStep[] = [
  { id: "frames", label: "Analyzing video frames...", icon: Film },
  { id: "subjects", label: "Detecting subjects...", icon: Layers },
  { id: "magic", label: "Applying AI magic...", icon: Wand2 },
  { id: "finalize", label: "Finalizing your creation...", icon: Sparkles },
];

function subtitleFromEffect(effect?: ApiEffect | null): string {
  const raw = (effect?.description ?? "").trim();
  if (!raw) return "Transform into comic art";
  const firstLine = raw.split(/\r?\n/)[0] ?? raw;
  return firstLine.length > 80 ? `${firstLine.slice(0, 77)}...` : firstLine;
}

function isTerminalStatus(status: string | undefined | null): boolean {
  return status === "completed" || status === "failed" || status === "expired";
}

type SearchParamsLike = { get: (key: string) => string | null };

function parseVideoId(params: SearchParamsLike): number | null {
  const raw = params.get("videoId");
  if (!raw) return null;
  const n = Number(raw);
  if (!Number.isFinite(n) || n <= 0) return null;
  return Math.trunc(n);
}

function parseUploadId(params: SearchParamsLike): string | null {
  const raw = params.get("uploadId");
  if (!raw) return null;
  return raw.trim() || null;
}

const FORBIDDEN_UPLOAD_HEADERS = new Set([
  "accept-encoding",
  "connection",
  "content-length",
  "cookie",
  "host",
  "origin",
  "referer",
  "user-agent",
]);

function normalizeUploadHeaders(
  headers: Record<string, string | string[]> | undefined,
  fallbackContentType: string,
): Record<string, string> {
  const normalized: Record<string, string> = {};
  if (headers) {
    for (const [key, value] of Object.entries(headers)) {
      const trimmedKey = key.trim();
      if (!trimmedKey) continue;
      if (FORBIDDEN_UPLOAD_HEADERS.has(trimmedKey.toLowerCase())) continue;
      if (Array.isArray(value)) {
        if (value[0]) normalized[trimmedKey] = value[0];
        continue;
      }
      if (value) normalized[trimmedKey] = value;
    }
  }

  const hasContentType = Object.keys(normalized).some((header) => header.toLowerCase() === "content-type");
  if (!hasContentType) {
    normalized["Content-Type"] = fallbackContentType;
  }

  return normalized;
}

function uploadWithProgress(opts: {
  url: string;
  headers: Record<string, string>;
  file: File;
  onProgress: (value: number) => void;
}): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("PUT", opts.url, true);

    Object.entries(opts.headers).forEach(([key, value]) => {
      xhr.setRequestHeader(key, value);
    });

    xhr.upload.onprogress = (event) => {
      if (!event.lengthComputable) return;
      const pct = Math.round((event.loaded / event.total) * 100);
      opts.onProgress(Math.min(100, Math.max(0, pct)));
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve();
      } else {
        reject(new Error(`Upload failed (${xhr.status}).`));
      }
    };

    xhr.onerror = () => {
      reject(new Error("Upload failed."));
    };

    xhr.send(opts.file);
  });
}

function formatUploadError(err: ApiError): string {
  const base = err.message || "Upload failed.";
  const requiredTokens = getRequiredTokensFromError(err);
  if (typeof requiredTokens === "number") {
    return `${base} (required tokens: ${requiredTokens})`;
  }

  return base;
}

export default function ProcessingClient({ slug }: { slug: string }) {
  const searchParams = useSearchParams();
  const { openAuth, openPlans } = useUiGuards();
  const [videoId, setVideoId] = useState<number | null>(() => parseVideoId(searchParams));
  const [uploadId, setUploadId] = useState<string | null>(() => parseUploadId(searchParams));

  const token = useAuthToken();
  const [authResolved, setAuthResolved] = useState(false);
  const [localPreviewUrl, setLocalPreviewUrl] = useState<string | null>(null);
  const [localPreviewReady, setLocalPreviewReady] = useState(false);
  const [previewNonce, setPreviewNonce] = useState(0);

  const [effectState, setEffectState] = useState<LoadState>({ status: "loading" });
  const [videoState, setVideoState] = useState<VideoPollState>({ status: "idle" });
  const [pollNotice, setPollNotice] = useState<string | null>(null);
  const [pollNonce, setPollNonce] = useState(0);
  const [requiredTokens, setRequiredTokens] = useState<number | null>(null);

  const [pendingFile, setPendingFile] = useState<File | null>(null);
  const [uploadStatus, setUploadStatus] = useState<"idle" | "loading" | "uploading" | "error" | "done">("idle");
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [uploadAttempt, setUploadAttempt] = useState(0);
  const uploadInFlightRef = useRef(false);
  const hasTokenShortage = requiredTokens !== null;

  // UI simulation state (local per page instance)
  const [doneSteps, setDoneSteps] = useState(0);
  const [progressValue, setProgressValue] = useState(8);
  const processingStartMsRef = useRef<number | null>(null);
  const prevShowResultRef = useRef(false);
  const activeToken = authResolved ? token ?? getAccessToken() : null;

  useEffect(() => {
    setAuthResolved(true);
  }, []);

  const replaceProcessingUrl = useCallback((nextVideoId: number | null, nextUploadId: string | null) => {
    if (typeof window === "undefined") return;
    const url = new URL(window.location.href);
    if (nextVideoId) {
      url.searchParams.set("videoId", String(nextVideoId));
    } else {
      url.searchParams.delete("videoId");
    }
    if (nextUploadId) {
      url.searchParams.set("uploadId", nextUploadId);
    } else {
      url.searchParams.delete("uploadId");
    }
    window.history.replaceState({}, "", url.toString());
  }, []);

  useEffect(() => {
    let cancelled = false;

    if (uploadId) {
      setLocalPreviewReady(false);
      setUploadStatus("loading");
      setUploadError(null);
      setRequiredTokens(null);

      void (async () => {
        const file = await loadPendingUpload(uploadId);
        if (cancelled) return;

        if (!file) {
          setPendingFile(null);
          setLocalPreviewUrl(null);
          setLocalPreviewReady(true);
          setUploadStatus("error");
          setUploadError("Upload session expired. Please reselect the video.");
          return;
        }

        setPendingFile(file);
        setLocalPreviewUrl(URL.createObjectURL(file));
        setLocalPreviewReady(true);
        setUploadStatus("idle");
      })();

      return () => {
        cancelled = true;
      };
    }

    if (!videoId) {
      setPendingFile(null);
      setLocalPreviewUrl(null);
      setLocalPreviewReady(true);
      return;
    }

    setPendingFile(null);

    const previewKey = `video_preview_${videoId}`;
    setLocalPreviewReady(false);

    try {
      const stored = window.sessionStorage.getItem(previewKey);
      if (stored) {
        setLocalPreviewUrl(stored);
        setLocalPreviewReady(true);
        return () => {
          cancelled = true;
        };
      }
    } catch {
      // ignore storage issues
    }

    void (async () => {
      const file = await loadPreview(videoId);
      if (cancelled) return;

      if (file) {
        const url = URL.createObjectURL(file);
        setLocalPreviewUrl(url);
      } else {
        setLocalPreviewUrl(null);
      }

      setLocalPreviewReady(true);
    })();

    return () => {
      cancelled = true;
    };
  }, [previewNonce, uploadId, videoId]);

  useEffect(() => {
    if (!localPreviewUrl || !localPreviewUrl.startsWith("blob:")) return;
    return () => {
      URL.revokeObjectURL(localPreviewUrl);
    };
  }, [localPreviewUrl]);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      setEffectState({ status: "loading" });
      try {
        const data = await getEffect(slug);
        if (cancelled) return;
        setEffectState({ status: "success", data });
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError) {
          if (err.status === 404) {
            setEffectState({ status: "not_found" });
            return;
          }
          setEffectState({ status: "error", message: err.message, code: err.status });
          return;
        }
        setEffectState({ status: "error", message: "Unexpected error while loading the effect." });
      }
    }

    void run();

    return () => {
      cancelled = true;
    };
  }, [slug]);

  useEffect(() => {
    if (!uploadId) return;
    if (uploadInFlightRef.current) return;
    if (uploadStatus === "uploading") return;
    if (uploadStatus === "error") return;
    if (!pendingFile) return;
    if (!token) {
      setUploadStatus("error");
      setUploadError("Sign in to upload your video.");
      return;
    }
    if (effectState.status !== "success") return;

    const guardKey = `upload_job_${uploadId}`;
    try {
      const existing = window.sessionStorage.getItem(guardKey);
      if (existing) {
        if (/^\d+$/.test(existing)) {
          const cachedVideoId = Number(existing);
          if (Number.isFinite(cachedVideoId) && cachedVideoId > 0) {
            setVideoId(cachedVideoId);
            setUploadId(null);
            replaceProcessingUrl(cachedVideoId, null);
            return;
          }
        }
        return;
      }
      window.sessionStorage.setItem(guardKey, "1");
    } catch {
      // ignore storage issues
    }

    uploadInFlightRef.current = true;
    setUploadStatus("uploading");
    setUploadError(null);
    setUploadProgress(0);

    const run = async () => {
      try {
        const mimeType = pendingFile.type || "video/mp4";
        const init = await initVideoUpload({
          effect_id: effectState.data.id,
          mime_type: mimeType,
          size: pendingFile.size,
          original_filename: pendingFile.name,
        });

        const uploadHeaders = normalizeUploadHeaders(init.upload_headers, mimeType);
        await uploadWithProgress({
          url: init.upload_url,
          headers: uploadHeaders,
          file: pendingFile,
          onProgress: (value) => setUploadProgress(value),
        });

        setUploadProgress(100);

        let promptPayload: Record<string, string> | null = null;
        if (uploadId) {
          try {
            const raw = window.sessionStorage.getItem(`upload_ctx_${uploadId}`);
            if (raw) {
              const parsed = JSON.parse(raw) as Record<string, unknown>;
              const positive = typeof parsed.positive_prompt === "string" ? parsed.positive_prompt.trim() : "";
              const negative = typeof parsed.negative_prompt === "string" ? parsed.negative_prompt.trim() : "";
              const nextPayload: Record<string, string> = {};
              if (positive) nextPayload.positive_prompt = positive;
              if (negative) nextPayload.negative_prompt = negative;
              if (Object.keys(nextPayload).length > 0) {
                promptPayload = nextPayload;
              }
            }
          } catch {
            // ignore prompt payload errors
          }
        }

        const video = await createVideo({
          effect_id: effectState.data.id,
          original_file_id: init.file.id,
          title: pendingFile.name,
          input_payload: promptPayload ?? undefined,
        });

        void savePreview(video.id, pendingFile);

        const idempotencyKey = `effect_${effectState.data.id}_${uploadId}`;
        const job = await submitAiJob({
          effect_id: effectState.data.id,
          video_id: video.id,
          idempotency_key: idempotencyKey,
          input_payload: promptPayload ?? undefined,
        });

        if (job.status === "failed") {
          throw new Error(job.error_message || "Processing job failed to queue.");
        }

        if (localPreviewUrl) {
          try {
            window.sessionStorage.setItem(`video_preview_${video.id}`, localPreviewUrl);
          } catch {
            // ignore storage issues
          }
        }

        try {
          window.sessionStorage.setItem(guardKey, String(video.id));
          if (uploadId) {
            window.sessionStorage.removeItem(`upload_ctx_${uploadId}`);
          }
        } catch {
          // ignore storage issues
        }

        await deletePendingUpload(uploadId);
        setUploadStatus("done");
        uploadInFlightRef.current = false;
        setVideoId(video.id);
        setUploadId(null);
        replaceProcessingUrl(video.id, null);
      } catch (err) {
        if (err instanceof ApiError) {
          const message = formatUploadError(err);
          setUploadError(message);
          const required = getRequiredTokensFromError(err);
          if (typeof required === "number") setRequiredTokens(required);
        } else if (err instanceof Error) {
          setUploadError(err.message || "Upload failed.");
        } else {
          setUploadError("Upload failed.");
        }
        setUploadStatus("error");
        try {
          window.sessionStorage.removeItem(guardKey);
        } catch {
          // ignore storage issues
        }
        uploadInFlightRef.current = false;
      }
    };

    void run();
  }, [
    effectState,
    localPreviewUrl,
    pendingFile,
    replaceProcessingUrl,
    slug,
    token,
    uploadAttempt,
    uploadId,
    uploadStatus,
  ]);

  // Poll video status.
  useEffect(() => {
    if (!token) {
      setVideoState({ status: "idle" });
      setPollNotice(null);
      return;
    }

    if (!videoId) {
      if (uploadId) {
        setVideoState({ status: "idle" });
        setPollNotice(null);
        return;
      }
      setVideoState({ status: "error", message: "Missing or invalid video id in the URL." });
      setPollNotice(null);
      return;
    }

    let cancelled = false;
    let timeoutId: number | null = null;

    const tick = async () => {
      let shouldContinue = true;

      try {
        setVideoState((prev) => (prev.status === "idle" ? { status: "loading" } : prev));
        const data = await getVideo(videoId);
        if (cancelled) return;

        setPollNotice(null);
        setVideoState({ status: "ready", data });
        if (isTerminalStatus(data.status)) {
          shouldContinue = false;
        }
      } catch (err) {
        if (cancelled) return;

        const message = err instanceof ApiError ? err.message : "Unable to refresh processing status.";
        const code = err instanceof ApiError ? err.status : undefined;
        setPollNotice(message);

        setVideoState((prev) => {
          // If we already have data, keep showing it (and keep retrying in background).
          if (prev.status === "ready") return prev;
          return { status: "error", message, code };
        });
      } finally {
        if (cancelled) return;
        if (shouldContinue) {
          timeoutId = window.setTimeout(() => void tick(), 2000);
        }
      }
    };

    void tick();

    return () => {
      cancelled = true;
      if (timeoutId) window.clearTimeout(timeoutId);
    };
  }, [pollNonce, token, videoId]);

  const videoStatus: string | null = videoState.status === "ready" ? (videoState.data.status ?? null) : null;
  const errorMessage: string | null = videoState.status === "ready" ? (videoState.data.error ?? null) : null;
  const processedFileUrl: string | null =
    videoState.status === "ready" ? (videoState.data.processed_file_url ?? null) : null;
  const originalFileUrl: string | null =
    videoState.status === "ready" ? (videoState.data.original_file_url ?? null) : null;
  const isPublic = videoState.status === "ready" ? Boolean(videoState.data.is_public) : false;

  const fallbackPreviewVideoUrl =
    effectState.status === "success" ? (effectState.data.preview_video_url ?? null) : null;
  const fallbackPreviewImageUrl =
    effectState.status === "success" ? (effectState.data.thumbnail_url ?? null) : null;

  const previewVideoUrl = localPreviewReady
    ? (localPreviewUrl ?? (uploadId ? null : originalFileUrl) ?? fallbackPreviewVideoUrl)
    : (localPreviewUrl ?? fallbackPreviewVideoUrl);
  const previewImageUrl = previewVideoUrl ? null : fallbackPreviewImageUrl;
  const previewKey = videoId ? `video_preview_${videoId}` : null;

  const isUploadPhase = !!uploadId && uploadStatus !== "done";
  const showResult = videoStatus === "completed";
  const activeStepper = videoStatus === "completed" ? 3 : isUploadPhase ? 1 : 2;
  const displayProgress = isUploadPhase ? uploadProgress : progressValue;

  const effectName = effectState.status === "success" ? effectState.data.name : "effect";
  const processingSubtitle =
    effectState.status === "success" ? subtitleFromEffect(effectState.data) : "Transform into comic art";
  const resultSubtitle =
    effectState.status === "success" ? subtitleFromEffect(effectState.data) : "Comic Book effect applied successfully";
  const watermarkLabel = effectState.status === "success" ? effectState.data.name : "AI Effect";
  const uploadAnotherHref =
    effectState.status === "success" && effectState.data.type === "configurable"
      ? `/effects/${encodeURIComponent(slug)}`
      : `/effects/${encodeURIComponent(slug)}?upload=1`;

  const handlePreviewError = () => {
    if (previewKey) {
      try {
        window.sessionStorage.removeItem(previewKey);
      } catch {
        // ignore storage issues
      }
    }
    if (localPreviewUrl) {
      setLocalPreviewUrl(null);
    }
    setLocalPreviewReady(false);
    setPreviewNonce((v) => v + 1);
  };

  useEffect(() => {
    if (!videoId) return;
    if (videoStatus !== "completed" && videoStatus !== "failed") return;

    void deletePreview(videoId);
    if (previewKey) {
      try {
        window.sessionStorage.removeItem(previewKey);
      } catch {
        // ignore storage issues
      }
    }
  }, [previewKey, videoId, videoStatus]);

  useEffect(() => {
    prevShowResultRef.current = showResult;
  }, [showResult]);

  // Keep progress moving while processing.
  useEffect(() => {
    if (videoStatus === "queued") {
      processingStartMsRef.current = null;
      setDoneSteps(0);
      setProgressValue(8);
      return;
    }

    if (videoStatus === "completed") {
      setDoneSteps(4);
      setProgressValue(100);
      return;
    }

    if (videoStatus === "failed") {
      setProgressValue((v) => Math.min(95, Math.max(8, v)));
    }
  }, [videoStatus]);

  useEffect(() => {
    if (videoStatus !== "processing") return;

    const estimateSecondsRaw =
      effectState.status === "success" ? Number(effectState.data.processing_time_estimate ?? 0) : 0;
    const lastSecondsRaw =
      effectState.status === "success" ? Number(effectState.data.last_processing_time_seconds ?? 0) : 0;
    const totalTimeSeconds = Number.isFinite(estimateSecondsRaw) && estimateSecondsRaw > 0
      ? estimateSecondsRaw
      : Number.isFinite(lastSecondsRaw) && lastSecondsRaw > 0
        ? lastSecondsRaw
        : 35;

    if (!processingStartMsRef.current) {
      processingStartMsRef.current = Date.now();
    }

    const intervalId = window.setInterval(() => {
      const start = processingStartMsRef.current ?? Date.now();
      const elapsedSeconds = Math.max(0, (Date.now() - start) / 1000);
      const ratio = Math.min(1, elapsedSeconds / totalTimeSeconds);

      // 10% → 95% over the total time; completion snaps to 100%.
      const target = 10 + ratio * 85;
      const clampedTarget = Math.min(95, Math.max(10, target));
      setProgressValue((prev) => Math.max(prev, clampedTarget));

      // Step boundaries: 1/9, 2/9, 8/9, 1 (AI magic = 2/3 total time).
      const nextDoneSteps =
        ratio >= 8 / 9 ? 3 : ratio >= 2 / 9 ? 2 : ratio >= 1 / 9 ? 1 : 0;
      setDoneSteps(nextDoneSteps);
    }, 250);

    return () => window.clearInterval(intervalId);
  }, [effectState, videoStatus]);

  const currentStepIndex = useMemo(() => {
    return Math.min(doneSteps, PROCESSING_STEPS.length - 1);
  }, [doneSteps]);

  const CurrentIcon = isUploadPhase ? UploadCloud : PROCESSING_STEPS[currentStepIndex]?.icon ?? Sparkles;

  const stepStatuses: StepStatus[] = useMemo(() => {
    if (videoStatus === "completed") {
      return PROCESSING_STEPS.map(() => "done");
    }

    const statuses: StepStatus[] = [];
    for (let i = 0; i < PROCESSING_STEPS.length; i++) {
      if (i < doneSteps) {
        statuses.push("done");
        continue;
      }

      if (i === currentStepIndex) {
        statuses.push(videoStatus === "failed" ? "error" : "running");
        continue;
      }

      statuses.push("pending");
    }
    return statuses;
  }, [currentStepIndex, doneSteps, videoStatus]);

  const uploadLabel =
    uploadStatus === "error"
      ? "Upload failed."
      : uploadStatus === "loading"
        ? "Preparing upload..."
        : "Uploading video...";

  const activeStepLabel = isUploadPhase
    ? uploadLabel
    : videoStatus === "completed"
      ? "Your creation is ready."
      : videoStatus === "failed"
        ? "Processing failed."
        : PROCESSING_STEPS[currentStepIndex]?.label ?? "Processing...";

  const stepBadgeClass = (step: number) =>
    step === activeStepper
      ? "grid h-7 w-7 place-items-center rounded-full border border-fuchsia-400/40 bg-fuchsia-500/15 text-xs font-semibold text-fuchsia-100 shadow-[0_0_0_4px_rgba(236,72,153,0.06)]"
      : "grid h-7 w-7 place-items-center rounded-full border border-white/10 bg-white/5 text-xs font-semibold text-white/60";

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl">
        <header className="flex items-center justify-start">
          <div className="text-xs font-semibold text-white/55">Processing...</div>
        </header>

        <div className="mt-4 flex items-center justify-center gap-3">
          <span className={stepBadgeClass(1)}>1</span>
          <span className="h-0.5 w-10 rounded-full bg-white/10" aria-hidden="true" />
          <span className={stepBadgeClass(2)}>2</span>
          <span className="h-0.5 w-10 rounded-full bg-white/10" aria-hidden="true" />
          <span className={stepBadgeClass(3)}>3</span>
        </div>

        {pollNotice ? (
          <div className="mt-5 rounded-2xl border border-amber-500/25 bg-amber-500/10 px-4 py-3 text-xs text-amber-100/80">
            {pollNotice}
          </div>
        ) : null}

        {isUploadPhase && uploadStatus === "error" ? (
          <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-5">
            <div className="text-sm font-semibold text-red-100">Upload failed</div>
            <div className="mt-1 text-xs text-red-100/75">
              {uploadError ?? "We couldn't upload your video. Please try again."}
            </div>
            {hasTokenShortage ? (
              <button
                type="button"
                onClick={() => openPlans(requiredTokens ?? 0)}
                className="mt-3 inline-flex h-10 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400"
              >
                Top up tokens
              </button>
            ) : null}
            <div className="mt-4 flex flex-col gap-2">
              {!hasTokenShortage ? (
                <button
                  type="button"
                  onClick={() => {
                    uploadInFlightRef.current = false;
                    setUploadStatus("idle");
                    setUploadError(null);
                    setUploadProgress(0);
                    setUploadAttempt((v) => v + 1);
                  }}
                  className="inline-flex h-11 w-full items-center justify-center rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                >
                  Retry upload
                </button>
              ) : null}
              <Link
                href={`/effects/${encodeURIComponent(slug)}`}
                className="inline-flex h-11 w-full items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-sm font-semibold text-white/80 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                Back to effect
              </Link>
            </div>
          </div>
        ) : null}

        {authResolved && !activeToken ? (
          <div className="mt-6 rounded-3xl border border-white/10 bg-white/5 p-5">
            <div className="text-sm font-semibold text-white">Sign in to see progress</div>
            <div className="mt-2 text-xs leading-5 text-white/60">
              Your processing status is tied to your account. Sign in to continue.
            </div>
            <button
              type="button"
              onClick={openAuth}
              className="mt-4 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Sign in
            </button>
          </div>
        ) : null}

        {activeToken && videoState.status === "error" ? (
          <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-5">
            <div className="flex items-start gap-3">
              <span className="mt-0.5 grid h-9 w-9 place-items-center rounded-2xl bg-red-500/15 text-red-200">
                <AlertTriangle className="h-5 w-5" />
              </span>
              <div className="min-w-0">
                <div className="text-sm font-semibold text-red-100">Couldn&apos;t load status</div>
                <div className="mt-1 text-xs text-red-100/70">
                  {videoState.code ? <span className="font-semibold">HTTP {videoState.code}</span> : null}
                  {videoState.code ? ": " : null}
                  {videoState.message}
                </div>
              </div>
            </div>
            <button
              type="button"
              onClick={() => setPollNonce((v) => v + 1)}
              className="mt-4 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Retry
            </button>
          </div>
        ) : null}

        {activeToken && videoState.status !== "error" ? (
          <main className="mt-6">
            <div className="mx-auto w-full max-w-sm">
              <div className="grid">
                <div
                  className={`col-start-1 row-start-1 transition-opacity duration-300 ${
                    showResult ? "opacity-100 pointer-events-auto" : "opacity-0 pointer-events-none"
                  }`}
                >
                  <ProcessingStepResult
                    processedFileUrl={processedFileUrl}
                    previewImageUrl={previewImageUrl}
                    effectName={effectName}
                    subtitle={resultSubtitle}
                    watermarkLabel={watermarkLabel}
                    videoId={videoId}
                    isPublic={isPublic}
                  />
                </div>
                <div
                  className={`col-start-1 row-start-1 transition-opacity duration-300 ${
                    !showResult && isUploadPhase ? "opacity-100 pointer-events-auto" : "opacity-0 pointer-events-none"
                  }`}
                >
                  <ProcessingStepUpload
                    previewVideoUrl={previewVideoUrl}
                    previewImageUrl={previewImageUrl}
                    onPreviewError={handlePreviewError}
                    currentIcon={CurrentIcon}
                    displayProgress={displayProgress}
                    activeStepLabel={activeStepLabel}
                    effectName={effectName}
                    subtitle={processingSubtitle}
                    uploadStatus={uploadStatus}
                    uploadProgress={uploadProgress}
                  />
                </div>
                <div
                  className={`col-start-1 row-start-1 transition-opacity duration-300 ${
                    !showResult && !isUploadPhase ? "opacity-100 pointer-events-auto" : "opacity-0 pointer-events-none"
                  }`}
                >
                  <ProcessingStepProcessing
                    previewVideoUrl={previewVideoUrl}
                    previewImageUrl={previewImageUrl}
                    onPreviewError={handlePreviewError}
                    currentIcon={CurrentIcon}
                    displayProgress={displayProgress}
                    activeStepLabel={activeStepLabel}
                    effectName={effectName}
                    subtitle={processingSubtitle}
                    steps={PROCESSING_STEPS}
                    stepStatuses={stepStatuses}
                    showError={videoStatus === "failed"}
                    errorMessage={errorMessage}
                    uploadAnotherHref={uploadAnotherHref}
                  />
                </div>
              </div>
            </div>
          </main>
        ) : null}
      </div>

    </div>
  );
}

