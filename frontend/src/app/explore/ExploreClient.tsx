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
      <div className="text-sm font-semibold text-white">{group.title}</div>
      <HorizontalCarousel className="mt-3 -mx-4" showRightFade scrollRef={scrollRef}>
        {group.items.map((item) => (
          <div key={item.id} className="snap-start w-44 sm:w-52">
            <PublicGalleryCard item={item} variant="explore" onOpen={() => onOpen(item)} onTry={() => onTry(item)} />
          </div>
        ))}
      </HorizontalCarousel>
      {showHint ? <p className="mt-2 text-center text-[11px] text-white/40">Swipe to explore</p> : null}
    </section>
  );
}

export default function ExploreClient() {
  const { requireAuth, requireAuthForNavigation } = useUiGuards();
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

  const handleTryItem = (item: GalleryVideo) => {
    clearUploadError();
    if (item.effect?.type === "configurable") {
      requireAuthForNavigation(`/explore/${item.id}`);
      return;
    }
    if (item.effect?.slug) {
      if (!requireAuth()) return;
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
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div className="mx-auto w-full max-w-md px-4 pb-12 pt-4 sm:max-w-xl lg:max-w-4xl">
        <div className="mt-4 flex items-center justify-between">
          <h1 className="text-base font-semibold text-white">Public Gallery</h1>
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
              <div className="absolute right-0 top-11 z-20 w-40 rounded-2xl border border-white/10 bg-[#111018] p-2 shadow-[0_12px_40px_rgba(0,0,0,0.35)]">
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
        </div>

        <div className="mt-4 flex items-center gap-2 rounded-2xl border border-white/10 bg-white/5 px-3 py-2">
          <Search className="h-4 w-4 text-white/50" />
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search videos or effects..."
            className="w-full bg-transparent text-xs text-white placeholder:text-white/40 focus:outline-none"
          />
        </div>

        <div className="mt-4 flex items-center justify-end">
          <SegmentedToggle
            value={viewMode}
            onChange={setViewMode}
            options={[
              { id: "grid", label: "Grid" },
              { id: "category", label: "By category" },
            ]}
          />
        </div>

        <main className="mt-5">
          {galleryState.status === "error" ? (
            <div className="rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
              {galleryState.message}
            </div>
          ) : null}
          {uploadError ? (
            <div className="mt-3 rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
              {uploadError}
            </div>
          ) : null}

          {galleryState.status === "loading" && items.length === 0 ? (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {Array.from({ length: 8 }).map((_, idx) => (
                <PublicGalleryCardSkeleton
                  key={idx}
                  variant="explore"
                  gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
                />
              ))}
            </div>
          ) : items.length === 0 && galleryState.status === "success" ? (
            <div className="rounded-2xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
              No public videos yet.
            </div>
          ) : showCategorySkeletons ? (
            <div className="space-y-6">
              {Array.from({ length: 3 }).map((_, sectionIdx) => (
                <section key={`category-skeleton-${sectionIdx}`}>
                  <div className="h-3 w-28 rounded bg-white/10 skeleton-shimmer" />
                  <HorizontalCarousel className="mt-3 -mx-4" showRightFade>
                    {Array.from({ length: 6 }).map((__, idx) => (
                      <div key={idx} className="snap-start w-44 sm:w-52">
                        <PublicGalleryCardSkeleton
                          variant="explore"
                          gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
                        />
                      </div>
                    ))}
                  </HorizontalCarousel>
                  <p className="mt-2 text-center text-[11px] text-white/40">Swipe to explore</p>
                </section>
              ))}
            </div>
          ) : viewMode === "grid" ? (
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
          ) : (
            <div className="space-y-6">
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

          <div ref={loadMoreRef} className="h-8" />
        </main>
      </div>
    </div>
  );
}
