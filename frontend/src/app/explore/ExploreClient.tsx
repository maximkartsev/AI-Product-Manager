"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { ApiError, getPublicGallery, type GalleryIndexData, type GalleryVideo } from "@/lib/api";
import useEffectUploadStart from "@/lib/useEffectUploadStart";
import { cn } from "@/lib/utils";
import { Search, SlidersHorizontal } from "lucide-react";
import HorizontalCarousel from "@/components/ui/HorizontalCarousel";
import useCarouselScrollHint from "@/components/ui/useCarouselScrollHint";
import SegmentedToggle from "@/components/ui/SegmentedToggle";
import { groupByOrdered } from "@/lib/grouping";
import { PublicGalleryCard, PublicGalleryCardSkeleton } from "@/components/cards/PublicGalleryCard";
import useUiGuards from "@/components/guards/useUiGuards";
import { EFFECT_GRADIENTS } from "@/lib/gradients";
import { IconGallery, IconSparkles } from "@/app/_components/landing/icons";

const SORT_OPTIONS = [
  { id: "trending", label: "Trending" },
  { id: "latest", label: "Latest" },
  { id: "liked", label: "Most Liked" },
];

type GalleryState =
  | { status: "idle" }
  | { status: "loading"; items: GalleryVideo[]; page: number; totalPages: number }
  | { status: "success"; items: GalleryVideo[]; page: number; totalPages: number }
  | { status: "error"; message: string };

type ViewMode = "grid" | "category";

type GroupedSection = {
  key: string;
  title: string;
  items: GalleryVideo[];
};

function CategoryGroupRow({
  group,
  onOpen,
  onTry,
}: {
  group: GroupedSection;
  onOpen: (item: GalleryVideo) => void;
  onTry: (item: GalleryVideo) => void;
}) {
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const showHint = useCarouselScrollHint({
    scrollRef,
    isLoading: false,
    deps: [group.items.length],
  });

  return (
    <section>
      {/* Subtle gradient divider */}
      <div className="pointer-events-none -mt-6 mb-4 h-px w-full" aria-hidden="true">
        <div className="mx-auto h-px w-2/3 bg-gradient-to-r from-transparent via-white/[0.06] to-transparent" />
      </div>

      <div className="flex items-center gap-2.5">
        <span className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true" />
        <div className="text-base font-semibold tracking-tight text-white sm:text-lg">{group.title}</div>
      </div>
      <HorizontalCarousel className="mt-4 -mx-4" showRightFade scrollRef={scrollRef}>
        {group.items.map((item) => (
          <div key={item.id} className="snap-start w-44 sm:w-52">
            <PublicGalleryCard item={item} variant="explore" onOpen={() => onOpen(item)} onTry={() => onTry(item)} />
          </div>
        ))}
      </HorizontalCarousel>
      {showHint ? <p className="mt-2 text-center text-[11px] text-white/30">Swipe to explore</p> : null}
    </section>
  );
}

export default function ExploreClient() {
  const { requireAuth, requireAuthForNavigation, ensureTokens } = useUiGuards();
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [sortOpen, setSortOpen] = useState(false);
  const [sortId, setSortId] = useState("trending");
  const [viewMode, setViewMode] = useState<ViewMode>("grid");
  const [page, setPage] = useState(1);
  const [galleryState, setGalleryState] = useState<GalleryState>({ status: "idle" });
  const [uploadError, setUploadError] = useState<string | null>(null);
  const {
    fileInputRef,
    startUpload,
    onFileSelected,
    clearUploadError,
  } = useEffectUploadStart({
    slug: null,
    onError: setUploadError,
  });
  const unavailableMessage = "Effect temporarily unavailable.";

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(search.trim());
    }, 250);
    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    setPage(1);
  }, [debouncedSearch]);

  useEffect(() => {
    let cancelled = false;

    setGalleryState((prev) => {
      const items = prev.status === "success" || prev.status === "loading" ? prev.items : [];
      const totalPages = prev.status === "success" || prev.status === "loading" ? prev.totalPages : 1;
      return { status: "loading", items, page, totalPages };
    });

    void (async () => {
      try {
        const order = sortId === "latest" ? "created_at:desc" : undefined;

        const data: GalleryIndexData = await getPublicGallery({
          page,
          perPage: 12,
          search: debouncedSearch || undefined,
          order,
        });

        if (cancelled) return;

        setGalleryState((prev) => {
          const existing = prev.status === "loading" && page > 1 ? prev.items : [];
          const merged = page > 1 ? [...existing, ...data.items] : data.items;
          return { status: "success", items: merged, page: data.page, totalPages: data.totalPages };
        });
      } catch (err) {
        if (cancelled) return;
        const message = err instanceof ApiError ? err.message : "Could not load gallery.";
        setGalleryState({ status: "error", message });
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [debouncedSearch, page, sortId]);


  const items = galleryState.status === "success" || galleryState.status === "loading" ? galleryState.items : [];
  const canLoadMore =
    (galleryState.status === "success" || galleryState.status === "loading") &&
    galleryState.page < galleryState.totalPages;
  const loadMoreRef = useRef<HTMLDivElement | null>(null);

  const activeSort = useMemo(() => SORT_OPTIONS.find((opt) => opt.id === sortId) ?? SORT_OPTIONS[0]!, [sortId]);
  const grouped = useMemo(
    () =>
      groupByOrdered(
        items,
        (item) => `cat:${item.effect?.category?.slug ?? "other"}`,
        (item) => item.effect?.category?.name ?? "Other",
      ),
    [items],
  );

  const showCategorySkeletons = viewMode === "category" && galleryState.status === "loading" && items.length === 0;

  const handleOpenItem = (item: GalleryVideo) => {
    requireAuthForNavigation(`/explore/${item.id}`);
  };

  const handleTryItem = async (item: GalleryVideo) => {
    clearUploadError();
    const isAvailable =
      item.effect?.publication_status === "published" && Boolean(item.effect?.is_active);
    if (!isAvailable) {
      setUploadError(unavailableMessage);
      return;
    }
    if (item.effect?.type === "configurable") {
      requireAuthForNavigation(`/explore/${item.id}`);
      return;
    }
    if (item.effect?.slug) {
      if (!requireAuth()) return;
      const creditsCost = Math.max(0, Math.ceil(Number(item.effect.credits_cost ?? 0)));
      const okTokens = await ensureTokens(creditsCost);
      if (!okTokens) return;
      const result = startUpload(item.effect.slug);
      if (!result.ok && result.reason === "unauthenticated") {
        requireAuth();
      }
      return;
    }
    requireAuthForNavigation(`/explore/${item.id}`);
  };

  useEffect(() => {
    if (!canLoadMore) return;
    const el = loadMoreRef.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0];
        if (!entry?.isIntersecting) return;
        if (galleryState.status === "loading") return;
        setPage((p) => p + 1);
      },
      { rootMargin: "200px" },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [canLoadMore, galleryState.status]);

  return (
    <div className="noise-overlay min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
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

        {/* Page header */}
        <div className="effects-entrance relative mt-4 flex items-center gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/25 to-violet-500/20">
            <IconGallery className="h-5 w-5 text-fuchsia-200" />
          </span>
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">
              Public{" "}
              <span className="bg-gradient-to-r from-fuchsia-400 via-violet-400 to-cyan-400 bg-clip-text text-transparent">
                Gallery
              </span>
            </h1>
            <p className="mt-0.5 text-sm text-white/50">
              Discover what creators are making with AI effects.
            </p>
          </div>
        </div>

        {/* Search bar */}
        <div className="effects-entrance effects-entrance-d1 relative mt-5 flex items-center gap-2 rounded-2xl border border-white/[0.07] bg-white/[0.03] px-3 py-2">
          <Search className="h-4 w-4 text-white/40" />
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search videos or effects..."
            className="w-full bg-transparent text-xs text-white placeholder:text-white/35 focus:outline-none"
          />
        </div>

        {/* Sort + toggle row */}
        <div className="effects-entrance effects-entrance-d2 relative mt-4 flex items-center justify-between gap-3">
          <div className="flex items-center gap-2.5">
            <span
              className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500"
              aria-hidden="true"
            />
            <span className="text-xs font-medium text-white/55">Browse</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="relative">
              <button
                type="button"
                onClick={() => setSortOpen((v) => !v)}
                className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
                aria-label="Filters"
              >
                <SlidersHorizontal className="h-4 w-4" />
              </button>
              {sortOpen ? (
                <div className="absolute right-0 top-11 z-20 w-40 rounded-2xl border border-white/10 bg-[#0d0d15] p-2 shadow-[0_16px_48px_rgba(0,0,0,0.45)]">
                  {SORT_OPTIONS.map((option) => (
                    <button
                      key={option.id}
                      type="button"
                      onClick={() => {
                        setSortId(option.id);
                        setSortOpen(false);
                      }}
                      className={cn(
                        "flex w-full items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold transition",
                        option.id === activeSort.id
                          ? "bg-white/10 text-fuchsia-200"
                          : "text-white/70 hover:bg-white/5",
                      )}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              ) : null}
            </div>
            <SegmentedToggle
              value={viewMode}
              onChange={setViewMode}
              options={[
                { id: "grid", label: "Grid" },
                { id: "category", label: "By category" },
              ]}
            />
          </div>
        </div>

        {/* Content */}
        <main className="effects-entrance effects-entrance-d3 relative mt-5">
          {galleryState.status === "error" ? (
            <div className="rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
              <div className="text-sm font-semibold text-red-100">Could not load gallery</div>
              <div className="mt-1 text-xs text-red-100/60">{galleryState.message}</div>
            </div>
          ) : null}
          {uploadError ? (
            <div className="mt-3 rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5 text-xs text-red-100">
              {uploadError}
            </div>
          ) : null}

          {galleryState.status === "loading" && items.length === 0 ? (
            viewMode === "grid" ? (
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {Array.from({ length: 8 }).map((_, idx) => (
                  <PublicGalleryCardSkeleton
                    key={idx}
                    variant="explore"
                    gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
                  />
                ))}
              </div>
            ) : (
              <div className="space-y-10">
                {Array.from({ length: 3 }).map((_, sectionIdx) => (
                  <section key={`category-skeleton-${sectionIdx}`}>
                    <div className="pointer-events-none -mt-6 mb-4 h-px w-full" aria-hidden="true">
                      <div className="mx-auto h-px w-2/3 bg-gradient-to-r from-transparent via-white/[0.04] to-transparent" />
                    </div>
                    <div className="flex items-center gap-2.5">
                      <span className="h-1 w-5 rounded-full bg-white/10 skeleton-shimmer" aria-hidden="true" />
                      <div className="h-4 w-28 rounded bg-white/10 skeleton-shimmer" />
                    </div>
                    <HorizontalCarousel className="mt-4 -mx-4" showRightFade>
                      {Array.from({ length: 6 }).map((__, idx) => (
                        <div key={idx} className="snap-start w-44 sm:w-52">
                          <PublicGalleryCardSkeleton
                            variant="explore"
                            gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
                          />
                        </div>
                      ))}
                    </HorizontalCarousel>
                  </section>
                ))}
              </div>
            )
          ) : items.length === 0 && galleryState.status === "success" ? (
            <div className="rounded-3xl border border-white/[0.07] bg-white/[0.03] p-8 text-center">
              <div className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/20 to-violet-500/15">
                <IconSparkles className="h-6 w-6 text-white/30" />
              </div>
              <div className="text-sm font-medium text-white/50">No public videos yet</div>
              <div className="mt-1 text-xs text-white/30">Check back soon for new creations.</div>
            </div>
          ) : showCategorySkeletons ? (
            <div className="space-y-10">
              {Array.from({ length: 3 }).map((_, sectionIdx) => (
                <section key={`category-skeleton-${sectionIdx}`}>
                  <div className="pointer-events-none -mt-6 mb-4 h-px w-full" aria-hidden="true">
                    <div className="mx-auto h-px w-2/3 bg-gradient-to-r from-transparent via-white/[0.04] to-transparent" />
                  </div>
                  <div className="flex items-center gap-2.5">
                    <span className="h-1 w-5 rounded-full bg-white/10 skeleton-shimmer" aria-hidden="true" />
                    <div className="h-4 w-28 rounded bg-white/10 skeleton-shimmer" />
                  </div>
                  <HorizontalCarousel className="mt-4 -mx-4" showRightFade>
                    {Array.from({ length: 6 }).map((__, idx) => (
                      <div key={idx} className="snap-start w-44 sm:w-52">
                        <PublicGalleryCardSkeleton
                          variant="explore"
                          gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
                        />
                      </div>
                    ))}
                  </HorizontalCarousel>
                </section>
              ))}
            </div>
          ) : viewMode === "grid" ? (
            <div className="relative">
              {/* Ambient glow for grid */}
              <div
                className="pointer-events-none absolute -top-12 left-1/2 h-32 w-64 -translate-x-1/2 rounded-full bg-violet-600/[0.05] blur-[70px]"
                aria-hidden="true"
              />
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {items.map((item) => (
                  <PublicGalleryCard
                    key={item.id}
                    variant="explore"
                    item={item}
                    onOpen={() => handleOpenItem(item)}
                    onTry={() => handleTryItem(item)}
                  />
                ))}
              </div>
            </div>
          ) : (
            <div className="space-y-10">
              {grouped.map((group) => (
                <CategoryGroupRow
                  key={group.key}
                  group={group}
                  onOpen={handleOpenItem}
                  onTry={handleTryItem}
                />
              ))}
            </div>
          )}

          {canLoadMore && galleryState.status === "loading" && items.length > 0 ? (
            <div className="mt-6 flex items-center justify-center gap-2 text-xs text-white/40">
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
              <span className="ml-1">Loading more</span>
            </div>
          ) : null}

          <div ref={loadMoreRef} className="h-8" />
        </main>
      </div>
    </div>
  );
}
