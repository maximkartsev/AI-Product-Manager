"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import useUiGuards from "@/components/guards/useUiGuards";
import EffectsFeedClient from "@/app/effects/EffectsFeedClient";
import {
  ApiError,
  clearAccessToken,
  deleteVideo,
  getAccessToken,
  getVideo,
  getVideosIndex,
  publishVideo,
  unpublishVideo,
  type VideoData,
} from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { cn } from "@/lib/utils";
import { IconSparkles } from "@/app/_components/landing/icons";
import HorizontalCarousel from "@/components/ui/HorizontalCarousel";
import useCarouselScrollHint from "@/components/ui/useCarouselScrollHint";
import UserVideoCard, { resolveVideoStatus, UserVideoCardSkeleton } from "@/components/cards/UserVideoCard";
import { EFFECT_GRADIENTS } from "@/lib/gradients";
import { ChevronLeft, Download, Globe2, Loader2, Trash2, Wand2, X } from "lucide-react";
import useAuthToken from "@/lib/useAuthToken";

type VideosState = {
  items: VideoData[];
  page: number;
  totalPages: number;
  loading: boolean;
  loadingMore: boolean;
  error?: string | null;
};

type ViewMode = "grid" | "grouped";

function isTerminalVideoStatus(status?: string | null): boolean {
  return status === "completed" || status === "failed" || status === "expired";
}

function GroupedVideoRow({
  group,
  onOpen,
  onRepeat,
}: {
  group: { key: string; name: string; slug: string | null; items: VideoData[] };
  onOpen: (video: VideoData) => void;
  onRepeat: (video: VideoData) => void;
}) {
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const showHint = useCarouselScrollHint({
    scrollRef,
    isLoading: false,
    deps: [group.items.length],
  });

  return (
    <section>
      <div className="flex items-center gap-2.5">
        <span
          className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500"
          aria-hidden="true"
        />
        <div className="text-base font-semibold tracking-tight text-white sm:text-lg">{group.name}</div>
      </div>
      <HorizontalCarousel className="mt-3 -mx-4" showRightFade scrollRef={scrollRef}>
        {group.items.map((video) => (
          <div key={video.id} className="snap-start">
            <UserVideoCard
              variant="carousel"
              video={video}
              onOpen={() => onOpen(video)}
              onRepeat={() => onRepeat(video)}
            />
          </div>
        ))}
      </HorizontalCarousel>
      {showHint ? <p className="mt-2 text-center text-[11px] text-white/30">Swipe to explore</p> : null}
    </section>
  );
}

export default function UserVideosClient() {
  const router = useRouter();
  const token = useAuthToken();
  const [authResolved, setAuthResolved] = useState(false);
  const { openAuth } = useUiGuards();
  const [viewMode, setViewMode] = useState<ViewMode>("grid");
  const [drawerVideo, setDrawerVideo] = useState<VideoData | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerLoading, setDrawerLoading] = useState(false);
  const [drawerError, setDrawerError] = useState<string | null>(null);
  const [publishLoading, setPublishLoading] = useState(false);
  const [publishError, setPublishError] = useState<string | null>(null);
  const [deleteLoading, setDeleteLoading] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const [videosState, setVideosState] = useState<VideosState>({
    items: [],
    page: 0,
    totalPages: 1,
    loading: true,
    loadingMore: false,
  });
  const loadMoreRef = useRef<HTMLDivElement | null>(null);
  const activeToken = authResolved ? token ?? getAccessToken() : null;

  useEffect(() => {
    setAuthResolved(true);
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
        clearAccessToken();
      }
    }
  };

  useEffect(() => {
    if (!authResolved) return;
    if (!activeToken) {
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
  }, [activeToken, authResolved]);

  useEffect(() => {
    if (!authResolved || !activeToken) return;
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
  }, [activeToken, authResolved, videosState.loading, videosState.loadingMore, videosState.page, videosState.totalPages, videosState.error]);

  const handleOpenVideo = (video: VideoData) => {
    setDrawerError(null);
    setPublishError(null);
    setDrawerVideo(video);
  };

  const handleRepeatVideo = (video: VideoData) => {
    const slug = video.effect?.slug;
    if (!slug) return;
    const isConfigurable = video.effect?.type === "configurable";
    const href = isConfigurable
      ? `/effects/${encodeURIComponent(slug)}`
      : `/effects/${encodeURIComponent(slug)}?upload=1`;
    router.push(href);
  };

  const handlePublishToGallery = async () => {
    if (!drawerVideo) return;
    if (publishLoading) return;
    if (drawerVideo.status !== "completed" || !drawerVideo.processed_file_url) return;
    setPublishLoading(true);
    setPublishError(null);
    try {
      await publishVideo(drawerVideo.id);
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

  const handleDeleteVideo = async () => {
    if (!drawerVideo || deleteLoading) return;
    setDeleteLoading(true);
    try {
      await deleteVideo(drawerVideo.id);
      setVideosState((prev) => ({
        ...prev,
        items: prev.items.filter((v) => v.id !== drawerVideo.id),
      }));
      handleCloseDrawer();
    } catch (err) {
      setDrawerError(err instanceof ApiError ? err.message : "Unable to delete the video.");
    } finally {
      setDeleteLoading(false);
      setDeleteConfirm(false);
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
      setDeleteConfirm(false);
    }, 280);
  };

  useEffect(() => {
    if (!drawerVideo) return;
    setDrawerOpen(false);
    const raf = window.requestAnimationFrame(() => setDrawerOpen(true));
    return () => window.cancelAnimationFrame(raf);
  }, [drawerVideo?.id]);

  useEffect(() => {
    if (!drawerVideo || !activeToken) return;
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
  }, [drawerVideo?.id, activeToken]);

  const showAuthPrompt = authResolved && !activeToken;
  const showEmptyState =
    !showAuthPrompt && !videosState.loading && !videosState.error && videosState.items.length === 0;
  const drawerIsPublic = Boolean(drawerVideo?.is_public);
  const drawerPublishEnabled = Boolean(
    drawerVideo && drawerVideo.status === "completed" && drawerVideo.processed_file_url,
  );
  const drawerEffectAvailable =
    drawerVideo?.effect?.publication_status === "published" &&
    Boolean(drawerVideo?.effect?.is_active);
  const drawerRepeatLabel = drawerEffectAvailable ? "Repeat" : "Effect Unavailable";
  const drawerRepeatTitle = !drawerEffectAvailable ? "Effect temporarily unavailable." : undefined;

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
    <div className="noise-overlay min-h-screen overflow-x-hidden bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="relative mx-auto w-full max-w-md px-4 pb-12 pt-4 sm:max-w-xl lg:max-w-4xl">
        {/* Ambient background glows */}
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
          <div
            className="absolute -left-32 top-12 h-64 w-64 rounded-full bg-fuchsia-600/15 blur-[100px]"
            style={{ animation: "glow-drift 14s ease-in-out infinite" }}
          />
          <div
            className="absolute -right-20 top-72 h-48 w-48 rounded-full bg-violet-600/10 blur-[80px]"
            style={{ animation: "glow-drift-reverse 16s ease-in-out infinite" }}
          />
          <div
            className="absolute left-1/3 top-[55%] h-40 w-40 rounded-full bg-cyan-500/[0.07] blur-[90px]"
            style={{ animation: "glow-drift 18s ease-in-out infinite 3s" }}
          />
        </div>

        <section className="effects-entrance relative flex items-center justify-between">
          <div className="flex items-center gap-3">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/25 to-violet-500/20">
              <IconSparkles className="h-5 w-5 text-fuchsia-200" />
            </span>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">
                My{" "}
                <span className="bg-gradient-to-r from-fuchsia-400 via-violet-400 to-cyan-400 bg-clip-text text-transparent">
                  Videos
                </span>
              </h1>
              <p className="mt-0.5 text-sm text-white/50">Your processed AI video creations.</p>
            </div>
          </div>
          <Link
            href="/explore"
            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
            aria-label="Public gallery"
          >
            <Globe2 className="h-4 w-4" />
          </Link>
        </section>

        <main className="relative mt-6">
          {!showAuthPrompt && !showEmptyState ? (
            <div className="effects-entrance effects-entrance-d1 mb-4 flex items-center justify-between gap-3">
              <div className="flex items-center gap-2.5">
                <span
                  className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500"
                  aria-hidden="true"
                />
                <span className="text-xs font-medium text-white/55">Your creations</span>
              </div>
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
            <div className="effects-entrance effects-entrance-d1 rounded-3xl border border-white/[0.07] bg-white/[0.03] p-6 text-center">
              <div className="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-full bg-gradient-to-br from-fuchsia-500/20 to-violet-500/15">
                <IconSparkles className="h-5 w-5 text-fuchsia-200" />
              </div>
              <div className="text-sm font-semibold text-white">Sign in to see your videos</div>
              <div className="mt-2 text-xs text-white/60">
                Your processed videos are stored in your account.
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

          {videosState.error ? (
            <div className="rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
              <div className="text-sm font-semibold text-red-100">Error</div>
              <div className="mt-1 text-xs text-red-100/75">{videosState.error}</div>
            </div>
          ) : null}

          {!showAuthPrompt && videosState.loading && videosState.items.length === 0 ? (
            viewMode === "grid" ? (
              <div className="effects-entrance effects-entrance-d2 flex flex-wrap -mx-1.5">
                {Array.from({ length: 6 }).map((_, idx) => (
                  <div key={idx} className="mb-3 w-1/2 px-1.5 lg:w-1/4">
                    <UserVideoCardSkeleton
                      variant="grid"
                      gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
                    />
                  </div>
                ))}
              </div>
            ) : (
              <div className="effects-entrance effects-entrance-d2 space-y-6">
                {Array.from({ length: 2 }).map((_, sectionIdx) => (
                  <section key={sectionIdx}>
                    <div className="flex items-center gap-2.5">
                      <span className="h-1 w-5 rounded-full bg-white/10 skeleton-shimmer" />
                      <div className="h-4 w-28 rounded bg-white/10 skeleton-shimmer" />
                    </div>
                    <HorizontalCarousel className="mt-3 -mx-4" showRightFade>
                      {Array.from({ length: 6 }).map((__, idx) => (
                        <div key={idx} className="snap-start">
                          <UserVideoCardSkeleton
                            variant="carousel"
                            gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
                          />
                        </div>
                      ))}
                    </HorizontalCarousel>
                    <p className="mt-2 text-center text-[11px] text-white/30">Swipe to explore</p>
                  </section>
                ))}
              </div>
            )
          ) : null}

          {showEmptyState ? (
            <div className="effects-entrance effects-entrance-d1 rounded-3xl border border-white/[0.07] bg-white/[0.03] p-6 text-center">
              <div className="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-full bg-gradient-to-br from-fuchsia-500/20 to-violet-500/15">
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
              <div className="effects-entrance effects-entrance-d2 relative">
                <div className="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-fuchsia-600/[0.06] blur-[60px]" aria-hidden="true" />
                <div className="flex flex-wrap -mx-1.5">
                  {videosState.items.map((video) => (
                    <div key={video.id} className="mb-3 w-1/2 px-1.5 lg:w-1/4">
                      <UserVideoCard
                        variant="grid"
                        video={video}
                        onOpen={() => handleOpenVideo(video)}
                        onRepeat={() => handleRepeatVideo(video)}
                      />
                    </div>
                  ))}
                </div>
              </div>
            ) : (
              <div className="effects-entrance effects-entrance-d2 space-y-6">
                {groupedVideos.map((group, idx) => (
                  <div key={group.key}>
                    {idx > 0 ? (
                      <div className="mb-6 h-px bg-gradient-to-r from-transparent via-white/[0.06] to-transparent" />
                    ) : null}
                    <GroupedVideoRow
                      group={group}
                      onOpen={handleOpenVideo}
                      onRepeat={handleRepeatVideo}
                    />
                  </div>
                ))}
              </div>
            )
          ) : null}

          {videosState.loadingMore ? (
            <div className="mt-6 flex items-center justify-center gap-1.5">
              <span
                className="h-1.5 w-1.5 rounded-full bg-fuchsia-400/60"
                style={{ animation: "dot-pulse 1.5s ease-in-out infinite" }}
              />
              <span
                className="h-1.5 w-1.5 rounded-full bg-violet-400/60"
                style={{ animation: "dot-pulse 1.5s ease-in-out infinite 0.3s" }}
              />
              <span
                className="h-1.5 w-1.5 rounded-full bg-cyan-400/60"
                style={{ animation: "dot-pulse 1.5s ease-in-out infinite 0.6s" }}
              />
            </div>
          ) : null}
          <div ref={loadMoreRef} className="h-8" />
        </main>

        <div className="relative mt-8">
          <div className="mb-8 h-px bg-gradient-to-r from-transparent via-white/[0.06] to-transparent" />
          <EffectsFeedClient showPopularSeeAll />
        </div>
      </div>

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
              "absolute right-0 top-0 h-full w-[80vw] sm:w-[24rem] bg-[#05050a] font-sans text-white shadow-2xl transition-transform duration-300 ease-out",
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
                  <div className="text-[11px] text-white/55">{resolveVideoStatus(drawerVideo.status).label}</div>
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
                <div className="mt-4 rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
                  <div className="text-sm font-semibold text-red-100">Error</div>
                  <div className="mt-1 text-xs text-red-100/75">{drawerError}</div>
                </div>
              ) : null}

              <section className="relative mt-4 aspect-[9/16] w-full overflow-hidden rounded-3xl border border-white/[0.07] bg-black">
                {drawerVideo.processed_file_url || drawerVideo.original_file_url ? (
                  <VideoPlayer
                    className="h-full w-full object-cover"
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
                    resolveVideoStatus(drawerVideo.status).className,
                  )}
                >
                  {resolveVideoStatus(drawerVideo.status).label}
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
                <div className="mt-4 rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
                  <div className="text-sm font-semibold text-red-100">Processing failed</div>
                  <div className="mt-1 text-xs text-red-100/75">{drawerVideo.error}</div>
                </div>
              ) : null}

              {publishError ? (
                <div className="mt-4 rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
                  <div className="text-sm font-semibold text-red-100">Publish error</div>
                  <div className="mt-1 text-xs text-red-100/75">{publishError}</div>
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
                  disabled={!drawerVideo.effect?.slug || !drawerEffectAvailable}
                  title={drawerRepeatTitle}
                  className="inline-flex h-11 items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-xs font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 disabled:opacity-70"
                >
                  <Wand2 className="h-4 w-4" />
                  {drawerRepeatLabel}
                </button>
              </div>

              <div className="mt-6 border-t border-white/[0.07] pt-4">
                {deleteConfirm ? (
                  <div className="space-y-2">
                    <p className="text-xs text-red-100/80">Are you sure? This cannot be undone.</p>
                    <div className="grid grid-cols-2 gap-2">
                      <button
                        type="button"
                        onClick={() => setDeleteConfirm(false)}
                        disabled={deleteLoading}
                        className="inline-flex h-10 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white/70 transition hover:bg-white/10 disabled:opacity-60"
                      >
                        Cancel
                      </button>
                      <button
                        type="button"
                        onClick={handleDeleteVideo}
                        disabled={deleteLoading}
                        className="inline-flex h-10 items-center justify-center gap-1.5 rounded-2xl border border-red-500/30 bg-red-500/15 text-xs font-semibold text-red-100 transition hover:bg-red-500/25 disabled:opacity-60"
                      >
                        {deleteLoading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                        Delete
                      </button>
                    </div>
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() => setDeleteConfirm(true)}
                    className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white/50 transition hover:border-red-500/20 hover:bg-red-500/10 hover:text-red-100"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete Video
                  </button>
                )}
              </div>
            </div>
          </aside>
        </div>
      ) : null}
    </div>
  );
}
