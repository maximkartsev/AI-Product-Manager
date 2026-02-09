import VideoPlayer from "@/components/video/VideoPlayer";
import ConfigurableCard from "@/components/ui/ConfigurableCard";
import { IconPlay } from "@/app/_components/landing/icons";
import { SlidersHorizontal } from "lucide-react";
import { gradientClass, gradientForSlug, type GradientStop } from "@/lib/gradients";
import type { ApiEffect } from "@/lib/api";
import { cn } from "@/lib/utils";
import { useEffect, useState } from "react";

type LandingEffect = {
  name: string;
  tagline: string;
  type?: string | null;
  is_premium?: boolean;
  thumbnail_url?: string | null;
  gradient: GradientStop;
  stats: { uses: string };
};

type EffectsFeedCardProps = {
  variant: "effectsFeed";
  effect: ApiEffect;
  onTry: () => void;
};

type EffectsGridCardProps = {
  variant: "effectsGrid";
  effect: ApiEffect;
  onOpen: () => void;
};

type LandingPopularCardProps = {
  variant: "landingPopular";
  effect: LandingEffect;
  onTry: () => void;
};

type EffectCardProps = EffectsFeedCardProps | EffectsGridCardProps | LandingPopularCardProps;

function formatUses(effect: ApiEffect): string | null {
  const rawScore = effect.popularity_score ?? 0;
  if (!Number.isFinite(rawScore) || rawScore <= 0) {
    return null;
  }
  const count = Math.max(0, Math.round(rawScore * 100));
  if (count >= 1000) {
    const value = count >= 10000 ? (count / 1000).toFixed(0) : (count / 1000).toFixed(1);
    return `${value}K uses`;
  }
  return `${count} uses`;
}

export function EffectCard(props: EffectCardProps) {
  if (props.variant === "landingPopular") {
    const { effect, onTry } = props;
    const g = gradientClass(effect.gradient.from, effect.gradient.to);
    const mediaSrcKey = effect.thumbnail_url ?? effect.preview_video_url ?? "";
    const [mediaReady, setMediaReady] = useState(!mediaSrcKey);

    useEffect(() => {
      setMediaReady(!mediaSrcKey);
    }, [mediaSrcKey]);

    const mediaClassName = cn(
      "absolute inset-0 h-full w-full object-cover transition-opacity duration-150",
      mediaReady ? "opacity-100" : "opacity-0",
    );
    const coverClassName = cn(
      "absolute inset-0 transition-opacity duration-150",
      mediaReady ? "opacity-0" : "opacity-100",
      !mediaReady && "skeleton-shimmer",
    );
    return (
      <ConfigurableCard
        frameClassName="w-44 overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.35)]"
        mediaClassName={`relative aspect-[9/12] bg-gradient-to-br ${g}`}
        bodyClassName="p-3"
        bodyInsideFrame
        media={
          <>
            {effect.thumbnail_url ? (
              <img
                className={mediaClassName}
                src={effect.thumbnail_url}
                alt={effect.name}
                onLoad={() => setMediaReady(true)}
                onError={() => setMediaReady(true)}
              />
            ) : effect.preview_video_url ? (
              <VideoPlayer
                className={mediaClassName}
                src={effect.preview_video_url}
                muted
                loop
                autoPlay
                playsInline
                preload="metadata"
                onLoadedData={() => setMediaReady(true)}
                onPlaying={() => setMediaReady(true)}
                onError={() => setMediaReady(true)}
              />
            ) : null}
            <div className={coverClassName} />
            {effect.is_premium ? (
              <span className="absolute left-3 top-3 inline-flex items-center rounded-full border border-white/20 bg-black/45 px-2.5 py-1 text-[10px] font-semibold text-white/90 backdrop-blur-sm">
                Premium
              </span>
            ) : null}
            {effect.type === "configurable" ? (
              <span className="absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white/85 backdrop-blur-sm">
                <SlidersHorizontal className="h-3.5 w-3.5" />
              </span>
            ) : null}
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.35),transparent_40%),radial-gradient(circle_at_70%_70%,rgba(0,0,0,0.35),transparent_60%)]" />
            <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />
            <button
              type="button"
              aria-label={`Preview ${effect.name}`}
              className="absolute inset-0 grid place-items-center text-white/90"
              onClick={onTry}
            >
              <span className="grid h-14 w-14 place-items-center rounded-full border border-white/25 bg-black/35 backdrop-blur-sm shadow-lg">
                <IconPlay className="h-6 w-6 translate-x-0.5" />
              </span>
            </button>
            <div className="absolute bottom-3 left-3 right-3 flex items-center justify-between gap-3">
              <div className="min-w-0">
                <div className="truncate text-sm font-semibold text-white">{effect.name}</div>
                <div className="truncate text-xs text-white/75">{effect.tagline}</div>
              </div>
              {!effect.is_premium ? (
                <div className="shrink-0 rounded-full border border-white/15 bg-black/35 px-2.5 py-1 text-[11px] font-medium text-white/80">
                  {effect.stats.uses}
                </div>
              ) : null}
            </div>
          </>
        }
        body={
          <div className="flex items-center justify-between gap-3">
            <button
              type="button"
              onClick={onTry}
              className="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Try This
            </button>
          </div>
        }
      />
    );
  }

  if (props.variant === "effectsGrid") {
    const { effect, onOpen } = props;
    const gradient = gradientForSlug(effect.slug);
    const g = gradientClass(gradient.from, gradient.to);
    const usesLabel = formatUses(effect) ?? (effect.is_new ? "New" : "Try it");
    const mediaSrcKey = effect.thumbnail_url ?? effect.preview_video_url ?? "";
    const [mediaReady, setMediaReady] = useState(!mediaSrcKey);

    useEffect(() => {
      setMediaReady(!mediaSrcKey);
    }, [mediaSrcKey]);

    const mediaClassName = cn(
      "absolute inset-0 h-full w-full object-cover transition-opacity duration-150",
      mediaReady ? "opacity-100" : "opacity-0",
    );
    const coverClassName = cn(
      "absolute inset-0 transition-opacity duration-150",
      mediaReady ? "opacity-0" : "opacity-100",
      !mediaReady && "skeleton-shimmer",
    );

    return (
      <ConfigurableCard
        as="button"
        type="button"
        onClick={onOpen}
        className="group overflow-hidden rounded-2xl border border-white/10 bg-white/5 text-left shadow-[0_10px_24px_rgba(0,0,0,0.25)] transition hover:border-white/20"
        mediaClassName={`relative aspect-[3/4] bg-gradient-to-br ${g}`}
        bodyClassName="p-3"
        media={
          <>
            {effect.thumbnail_url ? (
              <img
                className={mediaClassName}
                src={effect.thumbnail_url}
                alt={effect.name}
                onLoad={() => setMediaReady(true)}
                onError={() => setMediaReady(true)}
              />
            ) : effect.preview_video_url ? (
              <VideoPlayer
                className={mediaClassName}
                src={effect.preview_video_url}
                autoPlay
                loop
                muted
                playsInline
                preload="metadata"
                onLoadedData={() => setMediaReady(true)}
                onPlaying={() => setMediaReady(true)}
                onError={() => setMediaReady(true)}
              />
            ) : null}
            <div className={coverClassName} />
            <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/15 to-black/70" />
            <div className="absolute inset-0 grid place-items-center text-white/90">
              <span className="grid h-10 w-10 place-items-center rounded-full border border-white/25 bg-black/35 backdrop-blur-sm">
                <IconPlay className="h-4 w-4 translate-x-0.5" />
              </span>
            </div>
            {effect.is_premium ? (
              <span className="absolute left-2 top-2 inline-flex items-center rounded-full border border-white/20 bg-black/50 px-2 py-0.5 text-[9px] font-semibold text-white/90">
                Premium
              </span>
            ) : null}
          </>
        }
        body={
          <>
            <div className="truncate text-xs font-semibold text-white">{effect.name}</div>
            <div className="text-[10px] text-white/50">{usesLabel}</div>
          </>
        }
      />
    );
  }

  const { effect, onTry } = props;
  const gradient = gradientForSlug(effect.slug);
  const g = gradientClass(gradient.from, gradient.to);
  const usesLabel = formatUses(effect) ?? (effect.is_new ? "New" : "Try it");
  const mediaSrcKey = effect.thumbnail_url ?? effect.preview_video_url ?? "";
  const [mediaReady, setMediaReady] = useState(!mediaSrcKey);

  useEffect(() => {
    setMediaReady(!mediaSrcKey);
  }, [mediaSrcKey]);

  const mediaClassName = cn(
    "absolute inset-0 h-full w-full object-cover transition-opacity duration-150",
    mediaReady ? "opacity-100" : "opacity-0",
  );
  const coverClassName = cn(
    "absolute inset-0 transition-opacity duration-150",
    mediaReady ? "opacity-0" : "opacity-100",
    !mediaReady && "skeleton-shimmer",
  );

  return (
    <ConfigurableCard
      as="button"
      type="button"
      onClick={onTry}
      className="w-32 text-left sm:w-36"
      frameClassName="overflow-hidden rounded-2xl border border-white/10 bg-white/5 shadow-[0_10px_24px_rgba(0,0,0,0.25)]"
      mediaClassName={`relative aspect-[3/4] bg-gradient-to-br ${g}`}
      bodyClassName="mt-2"
      media={
        <>
          {effect.thumbnail_url ? (
            <img
              className={mediaClassName}
              src={effect.thumbnail_url}
              alt={effect.name}
              onLoad={() => setMediaReady(true)}
              onError={() => setMediaReady(true)}
            />
          ) : effect.preview_video_url ? (
            <VideoPlayer
              className={mediaClassName}
              src={effect.preview_video_url}
              autoPlay
              loop
              muted
              playsInline
              preload="metadata"
              onLoadedData={() => setMediaReady(true)}
              onPlaying={() => setMediaReady(true)}
              onError={() => setMediaReady(true)}
            />
          ) : null}
          <div className={coverClassName} />
          <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/15 to-black/60" />
          <div className="absolute inset-0 grid place-items-center text-white/90">
            <span className="grid h-10 w-10 place-items-center rounded-full border border-white/25 bg-black/35 backdrop-blur-sm">
              <IconPlay className="h-4 w-4 translate-x-0.5" />
            </span>
          </div>
          {effect.is_premium ? (
            <span className="absolute left-2 top-2 inline-flex items-center rounded-full border border-white/20 bg-black/50 px-2 py-0.5 text-[9px] font-semibold text-white/90">
              Premium
            </span>
          ) : null}
        </>
      }
      body={
        <>
          <div className="truncate text-xs font-semibold text-white">{effect.name}</div>
          <div className="text-[10px] text-white/50">{usesLabel}</div>
        </>
      }
    />
  );
}

export function EffectCardSkeleton({
  variant,
  gradient,
}: {
  variant: "effectsFeed" | "effectsGrid" | "landingPopular";
  gradient: GradientStop;
}) {
  const g = gradientClass(gradient.from, gradient.to);

  if (variant === "landingPopular") {
    return (
      <div className="snap-start">
        <ConfigurableCard
          frameClassName="w-44 overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.35)] skeleton-shimmer"
          mediaClassName={`relative aspect-[9/12] bg-gradient-to-br ${g}`}
          bodyClassName="p-3"
          bodyInsideFrame
          media={
            <>
              <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />
              <div className="absolute bottom-3 left-3 right-3">
                <div className="h-3 w-24 rounded bg-white/15" />
                <div className="mt-2 h-3 w-32 rounded bg-white/10" />
              </div>
            </>
          }
          body={
            <div className="flex items-center justify-between gap-3">
              <div className="h-3 w-20 rounded bg-white/10" />
              <div className="h-7 w-16 rounded-full bg-white/15" />
            </div>
          }
        />
      </div>
    );
  }

  if (variant === "effectsGrid") {
    return (
      <ConfigurableCard
        className="overflow-hidden rounded-2xl border border-white/10 bg-white/5 text-left shadow-[0_10px_24px_rgba(0,0,0,0.25)] skeleton-shimmer"
        mediaClassName={`relative aspect-[3/4] bg-gradient-to-br ${g}`}
        bodyClassName="p-3"
        media={
          <>
            <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />
            <div className="absolute inset-0 grid place-items-center">
              <span className="h-10 w-10 rounded-full border border-white/10 bg-white/10" />
            </div>
          </>
        }
        body={
          <>
            <div className="h-3 w-24 rounded bg-white/10" />
            <div className="mt-1 h-3 w-16 rounded bg-white/5" />
          </>
        }
      />
    );
  }

  return (
    <div className="snap-start">
      <ConfigurableCard
        className="w-32 sm:w-36"
        frameClassName="overflow-hidden rounded-2xl border border-white/10 bg-white/5 shadow-[0_10px_24px_rgba(0,0,0,0.25)] skeleton-shimmer"
        mediaClassName={`relative aspect-[3/4] bg-gradient-to-br ${g}`}
        bodyClassName="mt-2 skeleton-shimmer"
        media={<div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />}
        body={
          <>
            <div className="h-3 w-24 rounded bg-white/10" />
            <div className="mt-1 h-3 w-16 rounded bg-white/5" />
          </>
        }
      />
    </div>
  );
}
