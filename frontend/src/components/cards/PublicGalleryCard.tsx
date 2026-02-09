import { useEffect, useState } from "react";
import ConfigurableCard from "@/components/ui/ConfigurableCard";
import VideoPlayer from "@/components/video/VideoPlayer";
import { IconPlay, IconSparkles } from "@/app/_components/landing/icons";
import { SlidersHorizontal, Play } from "lucide-react";
import { gradientClass, gradientForSlug, type GradientStop } from "@/lib/gradients";
import type { GalleryVideo } from "@/lib/api";
import { cn } from "@/lib/utils";

type LandingGalleryItem = {
  id: string;
  title: string;
  effect: string;
  stats?: {
    likes?: string;
    views?: string;
  };
  gradient: GradientStop;
  thumbnail_url?: string | null;
  processed_file_url?: string | null;
};

type ExploreProps = {
  variant: "explore";
  item: GalleryVideo;
  onOpen: () => void;
  onTry: () => void;
};

type LandingProps = {
  variant: "landing";
  item: LandingGalleryItem;
  onOpen: () => void;
  onTry: () => void;
};

type PublicGalleryCardProps = ExploreProps | LandingProps;

export function PublicGalleryCard(props: PublicGalleryCardProps) {
  if (props.variant === "landing") {
    const { item, onOpen, onTry } = props;
    const g = gradientClass(item.gradient.from, item.gradient.to);
    const showPlayOverlay = !item.processed_file_url || Boolean(item.thumbnail_url);

    return (
      <ConfigurableCard
        role="button"
        tabIndex={0}
        onClick={onOpen}
        onKeyDown={(event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            onOpen();
          }
        }}
        className="group overflow-hidden rounded-3xl bg-white/5 text-left shadow-[0_10px_30px_rgba(0,0,0,0.25)] ring-1 ring-inset ring-white/10 transition hover:ring-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
        aria-label={`Open public gallery item: ${item.title}`}
        mediaClassName={`relative aspect-[9/13] bg-gradient-to-br ${g}`}
        bodyClassName="p-3"
        media={
          <>
            {item.thumbnail_url ? (
              <img className="absolute inset-0 h-full w-full object-cover" src={item.thumbnail_url} alt={item.title} />
            ) : item.processed_file_url ? (
              <VideoPlayer
                className="absolute inset-0 h-full w-full object-cover"
                src={item.processed_file_url}
                playsInline
                autoPlay
                loop
                muted
                preload="metadata"
              />
            ) : null}
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.28),transparent_45%),radial-gradient(circle_at_70%_70%,rgba(0,0,0,0.35),transparent_65%)]" />
            <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/75" />

            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                onTry();
              }}
              className="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full border border-white/15 bg-black/45 px-2.5 py-1 text-[11px] font-semibold text-white/90 backdrop-blur-sm transition hover:bg-black/60"
            >
              <span className="grid h-4 w-4 place-items-center rounded-full bg-white/15 text-fuchsia-100">
                <IconSparkles className="h-3 w-3" />
              </span>
              Try This
            </button>

            {showPlayOverlay ? (
              <div className="absolute inset-0 grid place-items-center">
                <span className="grid h-14 w-14 place-items-center rounded-full border border-white/25 bg-black/30 backdrop-blur-sm transition group-hover:scale-[1.02]">
                  <IconPlay className="h-6 w-6 translate-x-0.5 text-white/90" />
                </span>
              </div>
            ) : null}
          </>
        }
        body={
          <>
            <div className="truncate text-sm font-semibold text-white">{item.title}</div>
            <div className="mt-1 flex items-center justify-between gap-2 text-[11px] text-white/60">
              <span className="truncate">{item.effect}</span>
              {item.stats?.likes ? (
                <span className="inline-flex shrink-0 items-center gap-1 text-white/65">
                  <span aria-hidden="true">♥</span>
                  <span>{item.stats.likes}</span>
                </span>
              ) : null}
            </div>
            {item.stats?.views ? <div className="mt-0.5 text-[11px] text-white/45">{item.stats.views}</div> : null}
          </>
        }
      />
    );
  }

  const { item, onOpen, onTry } = props;
  const effectName = item.effect?.name ?? "AI Effect";
  const title = effectName.trim() || "AI Effect";
  const showPlayOverlay = !item.processed_file_url || Boolean(item.thumbnail_url);
  const isConfigurable = item.effect?.type === "configurable";
  const gradient = gradientForSlug(item.effect?.slug ?? String(item.id));
  const g = gradientClass(gradient.from, gradient.to);
  const mediaSrcKey = item.thumbnail_url ?? item.processed_file_url ?? "";
  const hasMedia = Boolean(mediaSrcKey);
  const [mediaReady, setMediaReady] = useState(!hasMedia);

  useEffect(() => {
    setMediaReady(!mediaSrcKey);
  }, [mediaSrcKey]);

  const mediaClassName = cn(
    "absolute inset-0 h-full w-full object-cover transition-opacity duration-150",
    mediaReady ? "opacity-100" : "opacity-0",
  );

  return (
    <ConfigurableCard
      role="button"
      tabIndex={0}
      onClick={onOpen}
      onKeyDown={(event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          onOpen();
        }
      }}
      className="group relative overflow-hidden rounded-3xl bg-white/5 text-left shadow-[0_10px_30px_rgba(0,0,0,0.25)] ring-1 ring-inset ring-white/10 transition hover:ring-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
      aria-label={`Open public gallery item: ${title}`}
      mediaClassName="relative aspect-[9/13] w-full"
      media={
        <>
          <div
            className={cn(
              `absolute inset-0 bg-gradient-to-br ${g} transition-opacity duration-150`,
              mediaReady ? "opacity-0" : "opacity-100",
              !mediaReady && "skeleton-shimmer",
            )}
          />
          {item.thumbnail_url ? (
            <img
              className={mediaClassName}
              src={item.thumbnail_url}
              alt={title}
              onLoad={() => setMediaReady(true)}
              onError={() => setMediaReady(true)}
            />
          ) : item.processed_file_url ? (
            <VideoPlayer
              className={mediaClassName}
              src={item.processed_file_url}
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
          <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/25 to-black/80" />

          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              onTry();
            }}
            className="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full border border-white/15 bg-black/45 px-2.5 py-1 text-[11px] font-semibold text-white/90 backdrop-blur-sm transition hover:bg-black/60"
          >
            <span className="grid h-4 w-4 place-items-center rounded-full bg-white/15 text-fuchsia-100">
              <IconSparkles className="h-3 w-3" />
            </span>
            Try This
          </button>
          {isConfigurable ? (
            <span className="absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white/85 backdrop-blur-sm">
              <SlidersHorizontal className="h-3.5 w-3.5" />
            </span>
          ) : null}

          {showPlayOverlay ? (
            <div className="absolute inset-0 grid place-items-center">
              <span className="grid h-12 w-12 place-items-center rounded-full border border-white/25 bg-black/30 backdrop-blur-sm transition group-hover:scale-[1.02]">
                <Play className="h-5 w-5 translate-x-0.5 text-white/90" />
              </span>
            </div>
          ) : null}

          <div className="absolute bottom-3 left-3 right-3">
            <div className="text-sm font-semibold text-white/80">{effectName}</div>
          </div>
        </>
      }
    />
  );
}

export function PublicGalleryCardSkeleton({
  variant,
  gradient,
}: {
  variant: "landing" | "explore";
  gradient: GradientStop;
}) {
  const g = gradientClass(gradient.from, gradient.to);

  if (variant === "explore") {
    return (
      <ConfigurableCard
        className="skeleton-shimmer overflow-hidden rounded-3xl bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.25)] ring-1 ring-inset ring-white/10"
        mediaClassName={`relative aspect-[9/13] w-full bg-gradient-to-br ${g}`}
        media={<div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />}
      />
    );
  }

  return (
    <ConfigurableCard
      className="skeleton-shimmer overflow-hidden rounded-3xl bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.25)] ring-1 ring-inset ring-white/10"
      mediaClassName={`relative aspect-[9/13] bg-gradient-to-br ${g}`}
      bodyClassName="p-3"
      media={
        <>
          <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />
          <div className="absolute bottom-3 left-3 right-3">
            <div className="h-3 w-24 rounded bg-white/15" />
            <div className="mt-2 h-3 w-16 rounded bg-white/10" />
          </div>
        </>
      }
      body={
        <>
          <div className="h-3 w-20 rounded bg-white/10" />
          <div className="mt-2 h-3 w-24 rounded bg-white/5" />
        </>
      }
    />
  );
}
