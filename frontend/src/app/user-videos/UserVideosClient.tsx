"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import AuthModal from "@/app/_components/landing/AuthModal";
import EffectsFeedClient from "@/app/effects/EffectsFeedClient";
import { ApiError, getAccessToken, getVideo, getVideosIndex, publishVideo, unpublishVideo, type VideoData } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { cn } from "@/lib/utils";
import { IconSparkles } from "@/app/_components/landing/icons";
import { ChevronLeft, Download, Globe2, Loader2, Play, Wand2, X } from "lucide-react";

type VideosState = {
  items: VideoData[];
  page: number;
  totalPages: number;
  loading: boolean;
  loadingMore: boolean;
  error?: string | null;
};

type ViewMode = "grid" | "grouped";

const STATUS_BADGES: Record<
  string,
  { label: string; className: string }
> = {
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

function resolveStatus(status?: string | null) {
  if (!status) return STATUS_BADGES.queued;
  return STATUS_BADGES[status] ?? { label: status, className: "bg-white/10 text-white/70 border-white/15" };
}

function isTerminalVideoStatus(status?: string | null): boolean {
  return status === "completed" || status === "failed" || status === "expired";
}

function VideoCard({
  video,
  onOpen,
  onRepeat,
  variant = "grid",
}: {
  video: VideoData;
  onOpen: () => void;
  onRepeat: () => void;
  variant?: "grid" | "carousel";
}) {
  const title = video.effect?.name?.trim() || "Untitled";
  const status = resolveStatus(video.status);
  const previewUrl = video.processed_file_url || video.original_file_url;
  const canOpen = true;
  const canRepeat = Boolean(video.effect?.slug);
  const canDownload = Boolean(video.processed_file_url);
  const isCarousel = variant === "carousel";
  const actionTextSize = isCarousel ? "text-[10px]" : "text-[11px]";

  return (
    <div className={cn("group text-left", isCarousel ? "w-28 sm:w-32" : "w-full")}>
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
                className="absolute inset-0 h-full w-full object-cover"
                src={previewUrl}
                playsInline
                autoPlay
                loop
                muted
                preload="metadata"
              />
            ) : (
              <div className="absolute inset-0 bg-gradient-to-br from-fuchsia-500/40 via-violet-500/30 to-cyan-400/30" />
            )}
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

      <div className={cn("mt-2 flex gap-1.5", isCarousel ? "flex-col" : "flex-row")}>
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
      </div>
    </div>
  );
}

export default function UserVideosClient() {
  const router = useRouter();
  const [token, setToken] = useState<string | null>(null);
  const [authOpen, setAuthOpen] = useState(false);
  const [viewMode, setViewMode] = useState<ViewMode>("grid");
  const [drawerVideo, setDrawerVideo] = useState<VideoData | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerLoading, setDrawerLoading] = useState(false);
  const [drawerError, setDrawerError] = useState<string | null>(null);
  const [publishLoading, setPublishLoading] = useState(false);
  const [publishError, setPublishError] = useState<string | null>(null);
  const [videosState, setVideosState] = useState<VideosState>({
    items: [],
    page: 0,
    totalPages: 1,
    loading: true,
    loadingMore: false,
  });
  const loadMoreRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const t = window.setTimeout(() => setToken(getAccessToken()), 0);
    return () => window.clearTimeout(t);
  }, []);

  const loadVideos = async (page: number) => {
    setVideosState((prev) => ({
      ...prev,
      loading: page === 1,
      loadingMore: page > 1,
      error: null,
    }));
    try {
      const data = await getVideosIndex({ page, perPage: 12, order: "created_at:desc" });
      setVideosState((prev) => ({
        ...prev,
        items: page === 1 ? data.items : [...prev.items, ...data.items],
        page: data.page,
        totalPages: data.totalPages,
        loading: false,
        loadingMore: false,
      }));
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unable to load your videos.";
      setVideosState((prev) => ({
        ...prev,
        loading: false,
        loadingMore: false,
        error: message,
      }));
      if (err instanceof ApiError && err.status === 401) {
        setToken(null);
      }
    }
  };

  useEffect(() => {
    if (!token) {
      setVideosState({
        items: [],
        page: 0,
        totalPages: 1,
        loading: false,
        loadingMore: false,
        error: null,
      });
      return;
    }
    void loadVideos(1);
  }, [token]);

  useEffect(() => {
    if (!token) return;
    const el = loadMoreRef.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0];
        if (!entry?.isIntersecting) return;
        if (videosState.loading || videosState.loadingMore) return;
        if (videosState.page >= videosState.totalPages) return;
        if (videosState.error) return;
        void loadVideos(videosState.page + 1);
      },
      { rootMargin: "200px" },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [token, videosState.loading, videosState.loadingMore, videosState.page, videosState.totalPages, videosState.error]);

  const handleOpenVideo = (video: VideoData) => {
    setDrawerError(null);
    setPublishError(null);
    setDrawerVideo(video);
  };

  const handleRepeatVideo = (video: VideoData) => {
    const slug = video.effect?.slug;
    if (!slug) return;
    router.push(`/effects/${encodeURIComponent(slug)}?upload=1`);
  };

  const handlePublishToGallery = async () => {
    if (!drawerVideo) return;
    if (publishLoading) return;
    if (drawerVideo.status !== "completed" || !drawerVideo.processed_file_url) return;
    setPublishLoading(true);
    setPublishError(null);
    try {
      const effectName = drawerVideo.effect?.name?.trim();
      const title = effectName ? `${effectName} Creation` : "My Creation";
      await publishVideo(drawerVideo.id, { title });
      setDrawerVideo((prev) => (prev ? { ...prev, is_public: true } : prev));
      setVideosState((prev) => ({
        ...prev,
        items: prev.items.map((v) => (v.id === drawerVideo.id ? { ...v, is_public: true } : v)),
      }));
    } catch (err) {
      setPublishError(err instanceof ApiError ? err.message : "Unable to publish the video.");
    } finally {
      setPublishLoading(false);
    }
  };

  const handleUnpublishFromGallery = async () => {
    if (!drawerVideo) return;
    if (publishLoading) return;
    setPublishLoading(true);
    setPublishError(null);
    try {
      await unpublishVideo(drawerVideo.id);
      setDrawerVideo((prev) => (prev ? { ...prev, is_public: false } : prev));
      setVideosState((prev) => ({
        ...prev,
        items: prev.items.map((v) => (v.id === drawerVideo.id ? { ...v, is_public: false } : v)),
      }));
    } catch (err) {
      setPublishError(err instanceof ApiError ? err.message : "Unable to unpublish the video.");
    } finally {
      setPublishLoading(false);
    }
  };

  const handleCloseDrawer = () => {
    setDrawerOpen(false);
    window.setTimeout(() => {
      setDrawerVideo(null);
      setDrawerLoading(false);
      setDrawerError(null);
      setPublishLoading(false);
      setPublishError(null);
    }, 280);
  };

  useEffect(() => {
    if (!drawerVideo) return;
    setDrawerOpen(false);
    const raf = window.requestAnimationFrame(() => setDrawerOpen(true));
    return () => window.cancelAnimationFrame(raf);
  }, [drawerVideo?.id]);

  useEffect(() => {
    if (!drawerVideo || !token) return;
    let cancelled = false;
    let timeoutId: number | null = null;

    const tick = async () => {
      if (cancelled) return;
      setDrawerLoading(true);
      setDrawerError(null);
      try {
        const fresh = await getVideo(drawerVideo.id);
        if (cancelled) return;

        const merged: VideoData = {
          ...drawerVideo,
          ...fresh,
          effect: drawerVideo.effect,
        };

        setDrawerVideo(merged);
        setVideosState((prev) => ({
          ...prev,
          items: prev.items.map((v) =>
            v.id === merged.id ? { ...v, ...merged, effect: v.effect ?? merged.effect } : v,
          ),
        }));

        if (!isTerminalVideoStatus(merged.status)) {
          timeoutId = window.setTimeout(() => void tick(), 2000);
        }
      } catch (err) {
        if (cancelled) return;
        const message = err instanceof ApiError ? err.message : "Unable to refresh this video.";
        setDrawerError(message);
      } finally {
        if (!cancelled) {
          setDrawerLoading(false);
        }
      }
    };

    void tick();

    return () => {
      cancelled = true;
      if (timeoutId) window.clearTimeout(timeoutId);
    };
  }, [drawerVideo?.id, token]);

  const showAuthPrompt = !token;
  const showEmptyState =
    !showAuthPrompt && !videosState.loading && !videosState.error && videosState.items.length === 0;
  const drawerIsPublic = Boolean(drawerVideo?.is_public);
  const drawerPublishEnabled = Boolean(
    drawerVideo && drawerVideo.status === "completed" && drawerVideo.processed_file_url,
  );

  const groupedVideos = useMemo(() => {
    const order: string[] = [];
    const map = new Map<
      string,
      { key: string; name: string; slug: string | null; items: VideoData[] }
    >();

    videosState.items.forEach((video) => {
      const effectName = video.effect?.name?.trim() || "Unknown Effect";
      const effectId = video.effect?.id;
      const key = effectId ? `id:${effectId}` : `name:${effectName}`;

      if (!map.has(key)) {
        map.set(key, {
          key,
          name: effectName,
          slug: video.effect?.slug ?? null,
          items: [],
        });
        order.push(key);
      }
      map.get(key)!.items.push(video);
    });

    return order.map((key) => map.get(key)!);
  }, [videosState.items]);

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="mx-auto w-full max-w-md px-4 pb-12 pt-4 sm:max-w-xl lg:max-w-4xl">
        <header className="flex items-center justify-between">
          <Link href="/" className="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-white">
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-white/10">
              <IconSparkles className="h-4 w-4 text-fuchsia-200" />
            </span>
            <span className="uppercase">DZZZS</span>
          </Link>
          <span className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-white/70">
            Creator
          </span>
        </header>

        <div className="mt-6 flex items-center justify-between">
          <button
            type="button"
            onClick={() => router.back()}
            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
            aria-label="Back"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <h1 className="text-base font-semibold text-white">My Videos</h1>
          <Link
            href="/explore"
            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
            aria-label="Public gallery"
          >
            <Globe2 className="h-4 w-4" />
          </Link>
        </div>

        <main className="mt-6">
          {!showAuthPrompt && !showEmptyState ? (
            <div className="mb-4 flex items-center justify-between gap-3">
              <div className="text-xs text-white/50">Your creations</div>
              <div className="inline-flex rounded-full border border-white/10 bg-white/5 p-1 text-[11px] font-semibold">
                <button
                  type="button"
                  onClick={() => setViewMode("grid")}
                  className={cn(
                    "rounded-full px-3 py-1 transition",
                    viewMode === "grid"
                      ? "bg-white text-black"
                      : "text-white/70 hover:text-white",
                  )}
                >
                  Grid
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("grouped")}
                  className={cn(
                    "rounded-full px-3 py-1 transition",
                    viewMode === "grouped"
                      ? "bg-white text-black"
                      : "text-white/70 hover:text-white",
                  )}
                >
                  By effect
                </button>
              </div>
            </div>
          ) : null}
          {showAuthPrompt ? (
            <div className="rounded-3xl border border-white/10 bg-white/5 p-6 text-center">
              <div className="text-sm font-semibold text-white">Sign in to see your videos</div>
              <div className="mt-2 text-xs text-white/60">
                Your processed videos are stored in your account.
              </div>
              <button
                type="button"
                onClick={() => setAuthOpen(true)}
                className="mt-4 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                Sign in
              </button>
            </div>
          ) : null}

          {videosState.error ? (
            <div className="rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
              {videosState.error}
            </div>
          ) : null}

          {videosState.loading && videosState.items.length === 0 ? (
            <div className="text-center text-xs text-white/50">Loading videos…</div>
          ) : null}

          {showEmptyState ? (
            <div className="rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
              <div className="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-full bg-white/10">
                <IconSparkles className="h-5 w-5 text-fuchsia-200" />
              </div>
              <div className="text-base font-semibold text-white">No videos yet</div>
              <div className="mt-2 text-xs text-white/60">
                Choose an effect below to create your first AI video.
              </div>
            </div>
          ) : null}

          {videosState.items.length > 0 ? (
            viewMode === "grid" ? (
              <div className="flex flex-wrap -mx-1.5">
                {videosState.items.map((video) => (
                  <div key={video.id} className="mb-3 w-1/2 px-1.5 lg:w-1/4">
                    <VideoCard
                      video={video}
                      onOpen={() => handleOpenVideo(video)}
                      onRepeat={() => handleRepeatVideo(video)}
                    />
                  </div>
                ))}
              </div>
            ) : (
              <div className="space-y-6">
                {groupedVideos.map((group) => (
                  <section key={group.key}>
                    <div className="flex items-center justify-between">
                      <div className="text-sm font-semibold text-white">{group.name}</div>
                    </div>
                    <div className="relative mt-3 -mx-4">
                      <div className="overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4">
                        <div className="flex gap-3">
                          {group.items.map((video) => (
                            <div key={video.id} className="snap-start">
                              <VideoCard
                                video={video}
                                onOpen={() => handleOpenVideo(video)}
                                onRepeat={() => handleRepeatVideo(video)}
                                variant="carousel"
                              />
                            </div>
                          ))}
                        </div>
                      </div>
                      {group.items.length > 1 ? (
                        <div className="pointer-events-none absolute right-0 top-0 h-full w-12 bg-gradient-to-l from-[#05050a]/30 via-[#05050a]/10 to-transparent" />
                      ) : null}
                    </div>
                    {group.items.length > 1 ? (
                      <p className="mt-2 text-center text-[11px] text-white/40">Swipe to explore</p>
                    ) : null}
                  </section>
                ))}
              </div>
            )
          ) : null}

          {videosState.loadingMore ? (
            <div className="mt-4 text-center text-xs text-white/50">Loading more…</div>
          ) : null}
          <div ref={loadMoreRef} className="h-8" />
        </main>

        <div className="mt-10">
          <EffectsFeedClient showPopularSeeAll />
        </div>
      </div>

      <AuthModal
        open={authOpen}
        onClose={() => {
          setAuthOpen(false);
          setToken(getAccessToken());
        }}
        initialMode="signin"
      />

      {drawerVideo ? (
        <div className="fixed inset-0 z-50">
          <div
            className={cn(
              "absolute inset-0 bg-black/60 transition-opacity duration-300",
              drawerOpen ? "opacity-100" : "opacity-0",
            )}
            aria-hidden="true"
          />
          <button
            type="button"
            className="absolute inset-0"
            onClick={handleCloseDrawer}
            aria-label="Close video details"
          />
          <aside
            className={cn(
              "absolute right-0 top-0 h-full w-[90vw] bg-[#05050a] font-sans text-white shadow-2xl transition-transform duration-300 ease-out",
              drawerOpen ? "translate-x-0" : "translate-x-full",
            )}
          >
            <div className="h-full overflow-y-auto px-4 py-5">
              <header className="flex items-center justify-between gap-3">
                <button
                  type="button"
                  onClick={handleCloseDrawer}
                  className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
                  aria-label="Back"
                >
                  <ChevronLeft className="h-4 w-4" />
                </button>
                <div className="min-w-0 text-center">
                  <div className="truncate text-sm font-semibold text-white">
                    {drawerVideo.effect?.name ?? "My Video"}
                  </div>
                  <div className="text-[11px] text-white/55">{resolveStatus(drawerVideo.status).label}</div>
                </div>
                <button
                  type="button"
                  onClick={handleCloseDrawer}
                  className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
                  aria-label="Close"
                >
                  <X className="h-4 w-4" />
                </button>
              </header>

              {drawerError ? (
                <div className="mt-4 rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
                  {drawerError}
                </div>
              ) : null}

              <section className="relative mt-4 aspect-[9/16] w-full overflow-hidden rounded-3xl border border-white/10 bg-black">
                {drawerVideo.processed_file_url || drawerVideo.original_file_url ? (
                  <VideoPlayer
                    className="h-full w-full object-contain"
                    src={drawerVideo.processed_file_url ?? drawerVideo.original_file_url}
                    playsInline
                    autoPlay
                    loop
                    muted
                    preload="metadata"
                  />
                ) : (
                  <div className="absolute inset-0 bg-gradient-to-br from-fuchsia-500/40 via-violet-500/30 to-cyan-400/30" />
                )}
                <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/25 to-black/80" />

                <span
                  className={cn(
                    "absolute left-3 top-3 inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold",
                    resolveStatus(drawerVideo.status).className,
                  )}
                >
                  {resolveStatus(drawerVideo.status).label}
                </span>

                {drawerLoading ? (
                  <span className="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full border border-white/10 bg-black/40 px-2.5 py-1 text-[11px] font-semibold text-white/80 backdrop-blur-sm">
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    Updating
                  </span>
                ) : null}

                <div className="absolute bottom-3 left-3 right-3">
                  <div className="text-[11px] font-semibold text-white/90">
                    {drawerVideo.effect?.name ?? "AI Effect"}
                  </div>
                </div>
              </section>

              {drawerVideo.status === "failed" && drawerVideo.error ? (
                <div className="mt-4 rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
                  {drawerVideo.error}
                </div>
              ) : null}

              {publishError ? (
                <div className="mt-4 rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
                  {publishError}
                </div>
              ) : null}

              <button
                type="button"
                onClick={drawerIsPublic ? undefined : handlePublishToGallery}
                disabled={!drawerPublishEnabled || drawerIsPublic || publishLoading}
                className={cn(
                  "mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 text-sm font-semibold text-white transition",
                  drawerIsPublic ? "bg-emerald-500/15 text-emerald-100" : "bg-white/10 hover:bg-white/15",
                  (!drawerPublishEnabled || publishLoading) && "opacity-60",
                )}
              >
                {publishLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Globe2 className="h-4 w-4" />}
                {drawerIsPublic ? "Published to Gallery" : "Publish to Gallery"}
              </button>
              {drawerIsPublic ? (
                <button
                  type="button"
                  onClick={handleUnpublishFromGallery}
                  disabled={publishLoading}
                  className="mt-2 inline-flex h-10 w-full items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white/70 transition hover:bg-white/10 disabled:opacity-60"
                >
                  {publishLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : "Unpublish"}
                </button>
              ) : null}

              <div className="mt-4 grid grid-cols-2 gap-2">
                <a
                  href={drawerVideo.processed_file_url ?? "#"}
                  download={drawerVideo.processed_file_url ? "processed-video.mp4" : undefined}
                  target={drawerVideo.processed_file_url ? "_blank" : undefined}
                  rel={drawerVideo.processed_file_url ? "noreferrer" : undefined}
                  aria-disabled={!drawerVideo.processed_file_url}
                  className={cn(
                    "inline-flex h-11 items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white/80 transition hover:bg-white/10",
                    !drawerVideo.processed_file_url && "pointer-events-none opacity-50",
                  )}
                >
                  <Download className="h-4 w-4" />
                  Download
                </a>

                <button
                  type="button"
                  onClick={() => handleRepeatVideo(drawerVideo)}
                  disabled={!drawerVideo.effect?.slug}
                  className="inline-flex h-11 items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-xs font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 disabled:opacity-70"
                >
                  <Wand2 className="h-4 w-4" />
                  Repeat
                </button>
              </div>
            </div>
          </aside>
        </div>
      ) : null}
    </div>
  );
}
