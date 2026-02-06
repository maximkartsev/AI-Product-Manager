"use client";

import VideoPlayer from "@/components/video/VideoPlayer";
import { cn } from "@/lib/utils";
import { Download, Globe, Settings2, Share2, Sparkles, Wand2 } from "lucide-react";
import { useEffect } from "react";

type ProcessingStepResultProps = {
  processedFileUrl: string | null;
  previewImageUrl: string | null;
  effectName: string;
  subtitle: string;
  watermarkLabel: string;
};

export default function ProcessingStepResult({
  processedFileUrl,
  previewImageUrl,
  effectName,
  subtitle,
  watermarkLabel,
}: ProcessingStepResultProps) {
  useEffect(() => {
    const rafId = window.requestAnimationFrame(() => {
      window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" });
    });
    return () => window.cancelAnimationFrame(rafId);
  }, [processedFileUrl]);

  return (
    <div className="mt-6">
      <section className="relative mb-6 aspect-[9/16] w-full overflow-hidden rounded-2xl border border-white/10 bg-white/5">
        {processedFileUrl ? (
          <VideoPlayer
            className="h-full w-full object-cover"
            src={processedFileUrl}
            playsInline
            autoPlay
            loop
            muted
            preload="metadata"
          />
        ) : previewImageUrl ? (
          <img className="h-full w-full object-cover" src={previewImageUrl} alt={effectName} />
        ) : (
          <div className="flex h-full w-full items-center justify-center text-xs text-white/50">Video preview</div>
        )}
        <div className="pointer-events-none absolute inset-0">
          <div className="absolute bottom-4 left-4 right-4 text-xs font-medium text-white/90 drop-shadow-lg">
            dzzzs.com • {watermarkLabel}
          </div>
        </div>
      </section>

      <div className="mb-6 text-center">
        <div className="text-xl font-semibold text-white">Your video is ready!</div>
        <div className="mt-1 text-sm text-white/55">{subtitle}</div>
      </div>

      <div className="mb-6 rounded-xl border border-fuchsia-400/30 bg-gradient-to-r from-fuchsia-500/20 to-violet-500/20 p-4 text-[11px] text-white/70">
        <div className="flex items-center gap-3">
          <span className="grid h-10 w-10 place-items-center rounded-full bg-fuchsia-500/20 text-fuchsia-200">
            <Sparkles className="h-5 w-5" />
          </span>
          <div>
            <div className="text-sm font-medium text-white">You earned $0.10 credit!</div>
            <div className="text-xs text-white/50">Use it towards Pro upgrade</div>
          </div>
        </div>
      </div>

      <div className="mb-6 grid grid-cols-2 gap-3">
        <button
          type="button"
          disabled
          className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white"
        >
          <Settings2 className="h-4 w-4" />
          Customize
        </button>
        <button
          type="button"
          disabled
          className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white"
        >
          <Share2 className="h-4 w-4" />
          Share
        </button>
      </div>

      <button
        type="button"
        disabled
        className="mb-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/10 text-sm font-semibold text-white"
      >
        <Globe className="h-5 w-5" />
        Publish to Gallery
      </button>
      <a
        href={processedFileUrl ?? "#"}
        className={cn(
          "mb-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300",
          !processedFileUrl && "pointer-events-none opacity-60",
        )}
        download={processedFileUrl ? "processed-video.mp4" : undefined}
        target={processedFileUrl ? "_blank" : undefined}
        rel={processedFileUrl ? "noreferrer" : undefined}
      >
        <Download className="h-5 w-5" />
        Download Video
      </a>
      <button
        type="button"
        disabled
        className="mb-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/5 text-sm font-semibold text-white"
      >
        View My Videos
      </button>
      <button
        type="button"
        disabled
        className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl text-xs font-semibold text-white"
      >
        <Wand2 className="h-4 w-4" />
        Create Another
      </button>
    </div>
  );
}
