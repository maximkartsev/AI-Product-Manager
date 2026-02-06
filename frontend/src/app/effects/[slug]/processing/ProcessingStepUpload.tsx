"use client";

import { Progress } from "@/components/ui/progress";
import VideoPlayer from "@/components/video/VideoPlayer";
import { cn } from "@/lib/utils";
import { UploadCloud } from "lucide-react";
import { type ComponentType, useEffect } from "react";

type UploadStatus = "idle" | "loading" | "uploading" | "error" | "done";

type ProcessingStepUploadProps = {
  previewVideoUrl: string | null;
  previewImageUrl: string | null;
  onPreviewError: () => void;
  currentIcon: ComponentType<{ className?: string }>;
  displayProgress: number;
  activeStepLabel: string;
  effectName: string;
  subtitle: string;
  uploadStatus: UploadStatus;
  uploadProgress: number;
};

export default function ProcessingStepUpload({
  previewVideoUrl,
  previewImageUrl,
  onPreviewError,
  currentIcon: CurrentIcon,
  displayProgress,
  activeStepLabel,
  effectName,
  subtitle,
  uploadStatus,
  uploadProgress,
}: ProcessingStepUploadProps) {
  useEffect(() => {
    if (uploadStatus !== "error") return;
    const rafId = window.requestAnimationFrame(() => {
      window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" });
    });
    return () => window.cancelAnimationFrame(rafId);
  }, [uploadStatus]);

  const isError = uploadStatus === "error";
  const detail =
    uploadStatus === "uploading"
      ? `${uploadProgress}%`
      : uploadStatus === "loading"
        ? "Preparing"
        : isError
          ? "Failed"
          : "";

  return (
    <>
      <div className="overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-[0_18px_60px_rgba(0,0,0,0.45)]">
        <div className="relative aspect-[9/13] w-full">
          {previewVideoUrl ? (
            <VideoPlayer
              className="absolute inset-0 h-full w-full object-cover opacity-60 brightness-125 saturate-110"
              src={previewVideoUrl}
              muted
              loop
              autoPlay
              playsInline
              preload="metadata"
              onError={onPreviewError}
            />
          ) : previewImageUrl ? (
            <img
              className="absolute inset-0 h-full w-full object-cover opacity-55 brightness-125 saturate-110"
              src={previewImageUrl}
              alt={effectName}
            />
          ) : null}
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(236,72,153,0.2),transparent_55%),radial-gradient(circle_at_80%_40%,rgba(99,102,241,0.18),transparent_60%)]" />
          <div className="absolute inset-0 bg-black/25" />

          <div className="absolute inset-0 grid place-items-center">
            <span className="relative grid h-16 w-16 place-items-center">
              <span className="grid h-16 w-16 place-items-center rounded-full border border-white/15 bg-fuchsia-500/15 text-fuchsia-200 shadow-lg backdrop-blur-sm animate-pulse">
                <CurrentIcon className="h-7 w-7" />
              </span>
              <span className="absolute inset-0 rounded-full border-2 border-fuchsia-400/30 border-t-fuchsia-200/80 animate-spin" />
            </span>
          </div>

          <div className="absolute inset-x-5 bottom-5">
            <Progress value={displayProgress} className="h-2" />
            <div className="mt-3 text-center text-xs font-semibold text-white/70">{activeStepLabel}</div>
          </div>
        </div>
      </div>

      <div className="mt-6 text-center">
        <div className="text-lg font-semibold tracking-tight text-white">
          Applying <span className="text-fuchsia-200">{effectName}</span>
        </div>
        <div className="mt-1 text-xs text-white/55">{subtitle}</div>
      </div>

      <div className="mt-6 grid gap-3">
        <div className="flex items-center justify-between gap-4 transition-all duration-300">
          <div className="flex min-w-0 items-center gap-3">
            <span
              className={cn(
                "grid h-10 w-10 shrink-0 place-items-center rounded-2xl border text-fuchsia-200",
                !isError && "border-fuchsia-400/25 bg-fuchsia-500/10 shadow-[0_0_20px_rgba(236,72,153,0.25)]",
                isError && "border-red-500/25 bg-red-500/10 text-red-200",
              )}
            >
              <UploadCloud className={cn("h-5 w-5", !isError && "animate-pulse")} />
            </span>
            <span className={cn("truncate text-sm", isError ? "text-red-100" : "text-white/80")}>
              Uploading video...
            </span>
          </div>
          <div className="shrink-0 text-xs font-semibold">
            {isError ? <span className="text-red-200">Failed</span> : null}
            {!isError && detail ? <span className="text-white/70">{detail}</span> : null}
          </div>
        </div>
      </div>

      <div className="mt-6 rounded-2xl border border-white/10 bg-black/25 px-4 py-3 text-[11px] text-white/65">
        <span className="font-semibold text-fuchsia-200">Did you know?</span> Our AI analyzes thousands of frames per
        second to create seamless effects.
      </div>
    </>
  );
}
