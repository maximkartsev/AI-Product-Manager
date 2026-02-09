"use client";

import { Progress } from "@/components/ui/progress";
import VideoPlayer from "@/components/video/VideoPlayer";
import { cn } from "@/lib/utils";
import Link from "next/link";
import { type ComponentType } from "react";

type StepStatus = "pending" | "running" | "done" | "error";

type ProcessingStep = {
  id: string;
  label: string;
  icon: ComponentType<{ className?: string }>;
};

type ProcessingStepProcessingProps = {
  previewVideoUrl: string | null;
  previewImageUrl: string | null;
  onPreviewError: () => void;
  currentIcon: ComponentType<{ className?: string }>;
  displayProgress: number;
  activeStepLabel: string;
  effectName: string;
  subtitle: string;
  steps: ProcessingStep[];
  stepStatuses: StepStatus[];
  showError: boolean;
  errorMessage: string | null;
  uploadAnotherHref: string;
};

export default function ProcessingStepProcessing({
  previewVideoUrl,
  previewImageUrl,
  onPreviewError,
  currentIcon: CurrentIcon,
  displayProgress,
  activeStepLabel,
  effectName,
  subtitle,
  steps,
  stepStatuses,
  showError,
  errorMessage,
  uploadAnotherHref,
}: ProcessingStepProcessingProps) {
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

      <div className="mt-4 rounded-2xl border border-white/15 bg-white/10 px-3 py-2.5 text-[11px] text-white/80 backdrop-blur-md shadow-[0_10px_30px_rgba(0,0,0,0.35)]">
        <div className="text-[11px] font-semibold text-white">Just a heads up</div>
        <div className="text-[10px] text-white/70">
          Processing can take a little while. Finished video will appear in your private gallery.
        </div>
      </div>

      <div className="mt-6 text-center">
        <div className="text-lg font-semibold tracking-tight text-white">
          Applying <span className="text-fuchsia-200">{effectName}</span>
        </div>
        <div className="mt-1 text-xs text-white/55">{subtitle}</div>
      </div>

      <div className="mt-6 grid gap-3">
        {steps.map((step, idx) => {
          const status = stepStatuses[idx] ?? "pending";
          const Icon = step.icon;
          const isDone = status === "done";
          const isRunning = status === "running";
          const isError = status === "error";

          const rowOpacity = isDone || isRunning || isError ? "opacity-100" : "opacity-40";

          return (
            <div
              key={step.id}
              className={cn(
                "flex items-center justify-between gap-4 transition-all duration-300",
                rowOpacity,
                isRunning && "translate-x-0.5",
              )}
            >
              <div className="flex min-w-0 items-center gap-3">
                <span
                  className={cn(
                    "grid h-10 w-10 shrink-0 place-items-center rounded-2xl border text-fuchsia-200",
                    isDone && "border-fuchsia-400/25 bg-fuchsia-500/10",
                    isRunning && "border-fuchsia-400/25 bg-fuchsia-500/10 shadow-[0_0_20px_rgba(236,72,153,0.25)]",
                    status === "pending" && "border-white/10 bg-white/5 text-white/60",
                    isError && "border-red-500/25 bg-red-500/10 text-red-200",
                  )}
                >
                  <Icon className={cn("h-5 w-5", isRunning && "animate-pulse")} />
                </span>
                <span className={cn("truncate text-sm", isError ? "text-red-100" : "text-white/80")}>
                  {step.label}
                </span>
              </div>

              <div className="shrink-0 text-xs font-semibold">
                {isDone ? <span className="text-fuchsia-200">Done</span> : null}
                {isError ? <span className="text-red-200">Failed</span> : null}
              </div>
            </div>
          );
        })}
      </div>

      {showError ? (
        <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-4">
          <div className="text-sm font-semibold text-red-100">Processing failed</div>
          <div className="mt-1 text-xs text-red-100/75">
            {errorMessage ? errorMessage : "Something went wrong while processing your video."}
          </div>
          <div className="mt-4">
            <Link
              href={uploadAnotherHref}
              className="inline-flex h-11 w-full items-center justify-center rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Upload another video
            </Link>
          </div>
        </div>
      ) : null}

      <div className="mt-6 rounded-2xl border border-white/10 bg-black/25 px-4 py-3 text-[11px] text-white/65">
        <span className="font-semibold text-fuchsia-200">Did you know?</span> Our AI analyzes thousands of frames per
        second to create seamless effects.
      </div>
    </>
  );
}
