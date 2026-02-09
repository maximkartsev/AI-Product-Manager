import { useEffect, useState } from "react";
import VideoPlayer from "@/components/video/VideoPlayer";
import ConfigurableCard from "@/components/ui/ConfigurableCard";
import { cn } from "@/lib/utils";
import { Download, Play, Wand2 } from "lucide-react";
import type { VideoData } from "@/lib/api";
import { gradientClass, gradientForSlug, type GradientStop } from "@/lib/gradients";

export type VideoStatusBadge = {
  label: string;
  className: string;
};

const STATUS_BADGES: Record<string, VideoStatusBadge> = {
  queued: {
    label: "Queued",
    className: "bg-white/10 text-white/70 border-white/15",
  },
  processing: {
    label: "Processing",
    className: "bg-amber-500/15 text-amber-100 border-amber-400/30",
  },
  completed: {
    label: "Ready",
    className: "bg-emerald-500/15 text-emerald-100 border-emerald-400/30",
  },
  failed: {
    label: "Failed",
    className: "bg-red-500/15 text-red-100 border-red-400/30",
  },
};

export function resolveVideoStatus(status?: string | null): VideoStatusBadge {
  if (!status) return STATUS_BADGES.queued;
  return STATUS_BADGES[status] ?? { label: status, className: "bg-white/10 text-white/70 border-white/15" };
}

type UserVideoCardProps = {
  variant: "grid" | "carousel";
  video: VideoData;
  onOpen: () => void;
  onRepeat: () => void;
};

export default function UserVideoCard({ variant, video, onOpen, onRepeat }: UserVideoCardProps) {
  const title = video.effect?.name?.trim() || "Untitled";
  const status = resolveVideoStatus(video.status);
  const previewUrl = video.processed_file_url || video.original_file_url;
  const canOpen = true;
  const canRepeat = Boolean(video.effect?.slug);
  const canDownload = Boolean(video.processed_file_url);
  const isCarousel = variant === "carousel";
  const actionTextSize = isCarousel ? "text-[10px]" : "text-[11px]";
  const gradient = gradientForSlug(video.effect?.slug ?? String(video.id));
  const g = gradientClass(gradient.from, gradient.to);
  const mediaSrcKey = previewUrl ?? "";
  const [mediaReady, setMediaReady] = useState(!mediaSrcKey);

  useEffect(() => {
    setMediaReady(!mediaSrcKey);
  }, [mediaSrcKey]);

  const hasMedia = Boolean(mediaSrcKey);
  const coverClassName = cn(
    `absolute inset-0 bg-gradient-to-br ${g} transition-opacity duration-150`,
    mediaReady ? "opacity-0" : "opacity-100",
    hasMedia && !mediaReady && "skeleton-shimmer",
  );

  const videoClassName = cn(
    "absolute inset-0 h-full w-full object-cover transition-opacity duration-150",
    mediaReady ? "opacity-100" : "opacity-0",
  );

  return (
    <ConfigurableCard
      className={cn("group text-left", isCarousel ? "w-28 sm:w-32" : "w-full")}
      media={
        <button
          type="button"
          onClick={onOpen}
          className="w-full text-left"
          aria-label={`Open video: ${title}`}
          disabled={!canOpen}
        >
          <div className="relative overflow-hidden rounded-2xl border border-white/10 bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.25)] transition group-hover:border-white/20 disabled:opacity-70">
            <div className="relative aspect-[9/13] w-full">
              {previewUrl ? (
                <VideoPlayer
                  className={videoClassName}
                  src={previewUrl}
                  playsInline
                  autoPlay
                  loop
                  muted
                  preload="metadata"
                  onLoadedData={() => setMediaReady(true)}
                  onPlaying={() => setMediaReady(true)}
                  onError={() => setMediaReady(true)}
                />
              ) : null}
              <div className={coverClassName} />
              <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/25 to-black/80" />

              <span
                className={cn(
                  "absolute left-2 top-2 inline-flex items-center rounded-full border px-2 py-0.5 font-semibold",
                  isCarousel ? "text-[9px]" : "text-[11px]",
                  status.className,
                )}
              >
                {status.label}
              </span>

              <div className="absolute inset-0 grid place-items-center">
                <span
                  className={cn(
                    "grid place-items-center rounded-full border border-white/25 bg-black/30 text-white/90 backdrop-blur-sm transition group-hover:scale-[1.02]",
                    isCarousel ? "h-9 w-9" : "h-11 w-11",
                  )}
                >
                  <Play className={cn("translate-x-0.5", isCarousel ? "h-4 w-4" : "h-5 w-5")} />
                </span>
              </div>

              <div className="absolute bottom-2 left-2 right-2">
                <div className={cn("font-semibold text-white/90", isCarousel ? "text-[11px]" : "text-xs")}>
                  {title}
                </div>
              </div>
            </div>
          </div>
        </button>
      }
      bodyClassName={cn("mt-2 flex gap-1.5", isCarousel ? "flex-col" : "flex-row")}
      body={
        <>
          <a
            href={video.processed_file_url ?? "#"}
            download={canDownload ? "processed-video.mp4" : undefined}
            target={canDownload ? "_blank" : undefined}
            rel={canDownload ? "noreferrer" : undefined}
            aria-disabled={!canDownload}
            className={cn(
              "inline-flex w-full items-center justify-center gap-1 rounded-xl border border-white/10 bg-white/5 px-2 py-1.5 font-semibold text-white/80 transition hover:bg-white/10",
              actionTextSize,
              !canDownload && "pointer-events-none opacity-50",
            )}
          >
            <Download className={cn(isCarousel ? "h-3 w-3" : "h-3.5 w-3.5")} />
            Download
          </a>
          <button
            type="button"
            onClick={onRepeat}
            disabled={!canRepeat}
            className={cn(
              "inline-flex w-full items-center justify-center gap-1 rounded-xl px-2 py-1.5 font-semibold text-white transition shadow-[0_12px_30px_rgba(236,72,153,0.25)]",
              "bg-gradient-to-r from-fuchsia-500 to-violet-500 hover:from-fuchsia-400 hover:to-violet-400",
              "disabled:opacity-50 disabled:shadow-none",
              actionTextSize,
            )}
          >
            <Wand2 className={cn(isCarousel ? "h-3 w-3" : "h-3.5 w-3.5")} />
            Repeat
          </button>
        </>
      }
    />
  );
}

export function UserVideoCardSkeleton({
  variant,
  gradient,
}: {
  variant: "grid" | "carousel";
  gradient: GradientStop;
}) {
  const isCarousel = variant === "carousel";
  const g = gradientClass(gradient.from, gradient.to);

  return (
    <ConfigurableCard
      className={cn(isCarousel ? "w-28 sm:w-32" : "w-full")}
      media={
        <div className="relative overflow-hidden rounded-2xl border border-white/10 bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.25)] skeleton-shimmer">
          <div className="relative aspect-[9/13] w-full">
            <div className={`absolute inset-0 bg-gradient-to-br ${g}`} />
            <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/25 to-black/80" />
            <div className="absolute inset-0 grid place-items-center">
              <span
                className={cn(
                  "grid place-items-center rounded-full border border-white/10 bg-white/10",
                  isCarousel ? "h-8 w-8" : "h-10 w-10",
                )}
              />
            </div>
            <div className="absolute bottom-2 left-2 right-2">
              <div className={cn("h-3 w-24 rounded bg-white/15", isCarousel && "h-2.5 w-20")} />
            </div>
          </div>
        </div>
      }
      bodyClassName={cn("mt-2 flex gap-1.5 skeleton-shimmer", isCarousel ? "flex-col" : "flex-row")}
      body={
        <>
          <div className="h-7 w-full rounded-xl bg-white/10" />
          <div className="h-7 w-full rounded-xl bg-white/10" />
        </>
      }
    />
  );
}
