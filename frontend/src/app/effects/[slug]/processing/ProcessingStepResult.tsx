"use client";

import VideoPlayer from "@/components/video/VideoPlayer";
import { publishVideo, unpublishVideo } from "@/lib/api";
import { cn } from "@/lib/utils";
import { Check, Download, Globe, Loader2, Settings2, Share2, Sparkles, Wand2, X } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

type ProcessingStepResultProps = {
  processedFileUrl: string | null;
  previewImageUrl: string | null;
  effectName: string;
  subtitle: string;
  watermarkLabel: string;
  videoId: number | null;
  isPublic: boolean;
  effectTags?: string[] | null;
};

function normalizeTagList(input: string[] | string | null | undefined): string[] {
  if (!input) return [];
  const raw = Array.isArray(input) ? input : input.split(",");
  const seen = new Set<string>();
  const result: string[] = [];

  raw.forEach((tag) => {
    if (typeof tag !== "string") return;
    const trimmed = tag.trim();
    if (!trimmed) return;
    const key = trimmed.toLowerCase();
    if (seen.has(key)) return;
    seen.add(key);
    result.push(trimmed);
  });

  return result;
}

function mergeTags(required: string[], extra: string[]): string[] {
  const seen = new Set<string>();
  const result: string[] = [];
  [...required, ...extra].forEach((tag) => {
    const key = tag.toLowerCase();
    if (seen.has(key)) return;
    seen.add(key);
    result.push(tag);
  });
  return result;
}

export default function ProcessingStepResult({
  processedFileUrl,
  previewImageUrl,
  effectName,
  subtitle,
  watermarkLabel,
  videoId,
  isPublic,
  effectTags,
}: ProcessingStepResultProps) {
  const router = useRouter();
  const [localIsPublic, setLocalIsPublic] = useState(isPublic);
  const [publishOpen, setPublishOpen] = useState(false);
  const [publishLoading, setPublishLoading] = useState(false);
  const [publishError, setPublishError] = useState<string | null>(null);
  const [publishTitle, setPublishTitle] = useState("");
  const [publishTags, setPublishTags] = useState("");
  const [publishedOpen, setPublishedOpen] = useState(false);

  const publishEnabled = Boolean(videoId && processedFileUrl);
  const defaultTitle = useMemo(() => {
    const trimmed = effectName?.trim();
    return trimmed ? `${trimmed} Creation` : "My Creation";
  }, [effectName]);
  const normalizedEffectTags = useMemo(() => normalizeTagList(effectTags), [effectTags]);
  const extraTags = useMemo(() => normalizeTagList(publishTags), [publishTags]);
  const combinedTags = useMemo(
    () => mergeTags(normalizedEffectTags, extraTags),
    [normalizedEffectTags, extraTags],
  );

  useEffect(() => {
    const rafId = window.requestAnimationFrame(() => {
      window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" });
    });
    return () => window.cancelAnimationFrame(rafId);
  }, [processedFileUrl]);

  useEffect(() => {
    setLocalIsPublic(isPublic);
  }, [isPublic]);

  const openPublish = () => {
    if (!publishEnabled) return;
    if (!publishTitle.trim()) {
      setPublishTitle(defaultTitle);
    }
    setPublishError(null);
    setPublishOpen(true);
  };

  const handlePublish = async () => {
    if (!videoId || !publishEnabled) return;
    setPublishLoading(true);
    setPublishError(null);
    try {
      const title = publishTitle.trim() || defaultTitle;
      const tags = combinedTags;
      const result = await publishVideo(videoId, {
        title,
        tags: tags.length ? tags : null,
      });
      setLocalIsPublic(true);
      setPublishOpen(false);
      setPublishedOpen(true);
    } catch (err) {
      setPublishError(err instanceof Error ? err.message : "Unable to publish the video.");
    } finally {
      setPublishLoading(false);
    }
  };

  const handleUnpublish = async () => {
    if (!videoId) return;
    setPublishLoading(true);
    setPublishError(null);
    try {
      await unpublishVideo(videoId);
      setLocalIsPublic(false);
    } catch (err) {
      setPublishError(err instanceof Error ? err.message : "Unable to unpublish the video.");
    } finally {
      setPublishLoading(false);
    }
  };

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
        onClick={localIsPublic ? undefined : openPublish}
        disabled={!publishEnabled || localIsPublic || publishLoading}
        className={cn(
          "mb-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 text-sm font-semibold text-white transition",
          localIsPublic ? "bg-emerald-500/15 text-emerald-100" : "bg-white/10",
          (!publishEnabled || publishLoading) && "opacity-60",
        )}
      >
        {publishLoading ? <Loader2 className="h-5 w-5 animate-spin" /> : <Globe className="h-5 w-5" />}
        {localIsPublic ? "Published to Gallery" : "Publish to Gallery"}
      </button>
      {localIsPublic ? (
        <button
          type="button"
          onClick={handleUnpublish}
          disabled={publishLoading}
          className="mb-3 inline-flex h-10 w-full items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white/70 transition hover:bg-white/10"
        >
          {publishLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : "Unpublish"}
        </button>
      ) : null}
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
        onClick={() => router.push("/user-videos")}
        className="mb-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/5 text-sm font-semibold text-white transition hover:bg-white/10"
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

      {publishOpen ? (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-4">
          <div className="w-full max-w-md rounded-3xl border border-white/10 bg-[#0f0f14] p-5 text-white shadow-[0_20px_60px_rgba(0,0,0,0.45)]">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm font-semibold">Publish to Gallery</div>
                <div className="mt-1 text-xs text-white/60">Share your creation with the community.</div>
              </div>
              <button
                type="button"
                onClick={() => setPublishOpen(false)}
                className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/70"
                aria-label="Close"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="mt-4 space-y-3">
              <label className="block text-[11px] font-semibold text-white/70">
                Title
                <input
                  value={publishTitle}
                  onChange={(event) => setPublishTitle(event.target.value)}
                  className="mt-1 w-full rounded-2xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                  placeholder={defaultTitle}
                />
              </label>
              <label className="block text-[11px] font-semibold text-white/70">
                Tags (comma separated)
                <input
                  value={publishTags}
                  onChange={(event) => setPublishTags(event.target.value)}
                  className="mt-1 w-full rounded-2xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                  placeholder="neon, glow, portrait"
                />
              </label>
            </div>

            {publishError ? (
              <div className="mt-3 rounded-2xl border border-red-500/20 bg-red-500/10 px-3 py-2 text-xs text-red-100">
                {publishError}
              </div>
            ) : null}

            <button
              type="button"
              onClick={handlePublish}
              disabled={publishLoading}
              className="mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 disabled:opacity-70"
            >
              {publishLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Globe className="h-4 w-4" />}
              Publish
            </button>
          </div>
        </div>
      ) : null}

      {publishedOpen ? (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-4">
          <div className="w-full max-w-md rounded-3xl border border-white/10 bg-[#0f0f14] p-5 text-white shadow-[0_20px_60px_rgba(0,0,0,0.45)]">
            <div className="flex items-center justify-between">
              <div className="text-sm font-semibold">Published!</div>
              <button
                type="button"
                onClick={() => setPublishedOpen(false)}
                className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/70"
                aria-label="Close"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="mt-4 flex flex-col items-center text-center">
              <span className="grid h-12 w-12 place-items-center rounded-full bg-fuchsia-500/20 text-fuchsia-200">
                <Check className="h-6 w-6" />
              </span>
              <div className="mt-3 text-base font-semibold text-white">Your video is now public!</div>
              <div className="mt-1 text-xs text-white/60">Check it out in the Explore gallery.</div>
            </div>

            <div className="mt-5 grid grid-cols-2 gap-3">
              <button
                type="button"
                onClick={() => {
                  router.push("/explore");
                  setPublishedOpen(false);
                }}
                className="inline-flex h-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white/80 transition hover:bg-white/10"
              >
                View in Gallery
              </button>
              <button
                type="button"
                onClick={() => setPublishedOpen(false)}
                className="inline-flex h-11 items-center justify-center rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-xs font-semibold text-white"
              >
                Done
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
