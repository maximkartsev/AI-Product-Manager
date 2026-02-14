"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import {
  ApiError,
  getCategories,
  getEffectsIndex,
  type ApiCategory,
  type ApiEffect,
} from "@/lib/api";
import HorizontalCarousel from "@/components/ui/HorizontalCarousel";
import useCarouselScrollHint from "@/components/ui/useCarouselScrollHint";
import { EffectCard, EffectCardSkeleton } from "@/components/cards/EffectCard";
import { EFFECT_GRADIENTS } from "@/lib/gradients";
import useUiGuards from "@/components/guards/useUiGuards";

type PopularState =
  | { status: "loading" }
  | { status: "success"; data: ApiEffect[] }
  | { status: "empty" }
  | { status: "error"; message: string };

type CategoryRowState = {
  category: ApiCategory;
  effects: ApiEffect[];
  page: number;
  totalPages: number;
  loading: boolean;
  loadingMore: boolean;
  error?: string | null;
};

type EffectsFeedClientProps = {
  showPopularSeeAll?: boolean;
};

function categoryEmoji(slug: string): string | null {
  const key = slug.toLowerCase();
  if (["popular", "trending"].includes(key)) return "🔥";
  if (["style", "styling"].includes(key)) return "🎨";
  if (["weather"].includes(key)) return "🌧️";
  if (["background", "backgrounds"].includes(key)) return "🖼️";
  if (["retro"].includes(key)) return "📼";
  if (["lighting"].includes(key)) return "💡";
  if (["fun"].includes(key)) return "✨";
  return "⭐";
}

function CategoryRow({
  row,
  onTry,
  onLoadMore,
}: {
  row: CategoryRowState;
  onTry: (effect: ApiEffect) => void;
  onLoadMore: (slug: string) => void;
}) {
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const showHint = useCarouselScrollHint({
    scrollRef,
    isLoading: row.loading,
    deps: [row.effects.length],
  });

  return (
    <section className="mt-10" id={row.category.slug}>
      {/* Subtle gradient divider */}
      <div className="pointer-events-none -mt-6 mb-4 h-px w-full" aria-hidden="true">
        <div className="mx-auto h-px w-2/3 bg-gradient-to-r from-transparent via-white/[0.06] to-transparent" />
      </div>

      <div className="flex items-end justify-between gap-6">
        <div>
          <div className="flex items-center gap-2.5">
            <span className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true" />
            <h2 className="text-base font-semibold tracking-tight text-white sm:text-lg">{row.category.name}</h2>
          </div>
          {row.category.description ? (
            <p className="mt-1 text-xs text-white/45 sm:text-sm">{row.category.description}</p>
          ) : null}
        </div>
        <Link
          href={`/effects/categories/${encodeURIComponent(row.category.slug)}`}
          className="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-white/10 bg-white/[0.03] px-3.5 py-1.5 text-xs font-semibold text-white/70 transition-all duration-200 hover:border-white/20 hover:bg-white/[0.06] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
        >
          See All
        </Link>
      </div>

      <HorizontalCarousel
        className="mt-4 -mx-4 lg:mx-0"
        showRightFade
        onReachEnd={() => onLoadMore(row.category.slug)}
        scrollRef={scrollRef}
      >
        {row.effects.length === 0 && row.loading
          ? EFFECT_GRADIENTS.map((g, idx) => (
              <EffectCardSkeleton key={idx} variant="effectsFeed" gradient={g} />
            ))
          : row.effects.map((effect) => (
              <EffectCard key={effect.slug} variant="effectsFeed" effect={effect} onTry={() => onTry(effect)} />
            ))}
        {row.loadingMore ? <EffectCardSkeleton variant="effectsFeed" gradient={EFFECT_GRADIENTS[0]} /> : null}
      </HorizontalCarousel>

      {showHint ? <p className="mt-2 text-center text-[11px] text-white/30">Swipe to explore</p> : null}
    </section>
  );
}

function CategoryRowSkeleton({ gradientIndex }: { gradientIndex: number }) {
  return (
    <section className="mt-10">
      <div className="pointer-events-none -mt-6 mb-4 h-px w-full" aria-hidden="true">
        <div className="mx-auto h-px w-2/3 bg-gradient-to-r from-transparent via-white/[0.04] to-transparent" />
      </div>
      <div className="flex items-end justify-between gap-6">
        <div>
          <div className="flex items-center gap-2.5">
            <span className="h-1 w-5 rounded-full bg-white/10 skeleton-shimmer" aria-hidden="true" />
            <div className="h-4 w-28 rounded bg-white/10 skeleton-shimmer" />
          </div>
          <div className="mt-1.5 h-3 w-36 rounded bg-white/5 skeleton-shimmer" />
        </div>
        <div className="h-7 w-16 rounded-full bg-white/[0.04] skeleton-shimmer" />
      </div>
      <HorizontalCarousel className="mt-4 -mx-4 lg:mx-0" showRightFade>
        {EFFECT_GRADIENTS.map((g, idx) => (
          <EffectCardSkeleton
            key={`cat-skeleton-${gradientIndex}-${idx}`}
            variant="effectsFeed"
            gradient={g}
          />
        ))}
      </HorizontalCarousel>
    </section>
  );
}

export default function EffectsFeedClient({ showPopularSeeAll = false }: EffectsFeedClientProps) {
  const { requireAuthForNavigation } = useUiGuards();
  const [popularState, setPopularState] = useState<PopularState>({ status: "loading" });
  const [categoryOrder, setCategoryOrder] = useState<string[]>([]);
  const [categoryRows, setCategoryRows] = useState<Record<string, CategoryRowState>>({});
  const [categoriesPage, setCategoriesPage] = useState(0);
  const [categoriesTotalPages, setCategoriesTotalPages] = useState(1);
  const [categoriesLoading, setCategoriesLoading] = useState(false);
  const [categoriesLoaded, setCategoriesLoaded] = useState(false);
  const [categoriesError, setCategoriesError] = useState<string | null>(null);
  const loadMoreRef = useRef<HTMLDivElement | null>(null);
  const categoryRowsRef = useRef<Record<string, CategoryRowState>>({});
  const popularCarouselRef = useRef<HTMLDivElement | null>(null);
  const popularCount = popularState.status === "success" ? popularState.data.length : 0;
  const showPopularHint = useCarouselScrollHint({
    scrollRef: popularCarouselRef,
    isLoading: popularState.status === "loading",
    deps: [popularCount],
  });

  useEffect(() => {
    categoryRowsRef.current = categoryRows;
  }, [categoryRows]);

  const loadPopular = async () => {
    setPopularState({ status: "loading" });
    try {
      const data = await getEffectsIndex({ perPage: 8, order: "popularity_score:desc" });
      const items = (data.items ?? []).filter((effect) => effect && effect.is_active);
      if (items.length === 0) {
        setPopularState({ status: "empty" });
        return;
      }
      setPopularState({ status: "success", data: items });
    } catch (err) {
      if (err instanceof ApiError) {
        setPopularState({ status: "error", message: err.message });
        return;
      }
      setPopularState({ status: "error", message: "Unexpected error while loading popular effects." });
    }
  };

  const setRowState = (slug: string, updater: (row: CategoryRowState) => CategoryRowState) => {
    setCategoryRows((prev) => {
      const current = prev[slug];
      if (!current) return prev;
      return { ...prev, [slug]: updater(current) };
    });
  };

  const loadCategoryEffects = async (slug: string, page: number) => {
    setRowState(slug, (row) => ({
      ...row,
      loading: page === 1 ? true : row.loading,
      loadingMore: page > 1 ? true : row.loadingMore,
      error: null,
    }));
    try {
      const data = await getEffectsIndex({
        page,
        perPage: 5,
        order: "id:desc",
        category: slug,
      });
      setRowState(slug, (row) => ({
        ...row,
        effects: page === 1 ? data.items : [...row.effects, ...data.items],
        page: data.page,
        totalPages: data.totalPages,
        loading: false,
        loadingMore: false,
      }));
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unable to load effects.";
      setRowState(slug, (row) => ({
        ...row,
        loading: false,
        loadingMore: false,
        error: message,
      }));
    }
  };

  const loadCategories = async (page: number) => {
    setCategoriesLoading(true);
    setCategoriesError(null);
    try {
      const data = await getCategories({ page, perPage: 5, order: "sort_order:asc,name:asc" });
      const existing = categoryRowsRef.current;
      const newSlugs = [...new Set(
        (data.items ?? [])
          .map((category) => category.slug)
          .filter((slug) => !existing[slug])
      )];

      setCategoryRows((prev) => {
        const next = { ...prev };
        for (const category of data.items ?? []) {
          if (next[category.slug]) continue;
          next[category.slug] = {
            category,
            effects: [],
            page: 0,
            totalPages: 1,
            loading: true,
            loadingMore: false,
          };
        }
        return next;
      });

      if (newSlugs.length > 0) {
        setCategoryOrder((prev) => [...prev, ...newSlugs]);
      }
      setCategoriesPage(data.page);
      setCategoriesTotalPages(data.totalPages);

      await Promise.all(newSlugs.map((slug) => loadCategoryEffects(slug, 1)));
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unable to load categories.";
      setCategoriesError(message);
    } finally {
      setCategoriesLoaded(true);
      setCategoriesLoading(false);
    }
  };

  useEffect(() => {
    void loadPopular();
    void loadCategories(1);
  }, []);

  useEffect(() => {
    const el = loadMoreRef.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0];
        if (!entry?.isIntersecting) return;
        if (categoriesLoading) return;
        if (categoriesPage >= categoriesTotalPages) return;
        void loadCategories(categoriesPage + 1);
      },
      { rootMargin: "200px" },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [categoriesLoading, categoriesPage, categoriesTotalPages]);

  const handleLoadMoreEffects = (slug: string) => {
    const row = categoryRows[slug];
    if (!row) return;
    if (row.loadingMore) return;
    if (row.page >= row.totalPages) return;
    void loadCategoryEffects(slug, row.page + 1);
  };

  const handleOpenEffect = (effect: ApiEffect) => {
    requireAuthForNavigation(`/effects/${encodeURIComponent(effect.slug)}`);
  };

  return (
    <>
      {popularState.status === "loading" ? (
        <section className="mt-10">
          <div className="flex items-end justify-between gap-6">
            <div>
              <div className="flex items-center gap-2.5">
                <span className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true" />
                <h2 className="text-base font-semibold tracking-tight text-white sm:text-lg">Popular Effects</h2>
              </div>
              <p className="mt-1 text-xs text-white/45 sm:text-sm">Trending transformations loved by creators.</p>
            </div>
          </div>
          <HorizontalCarousel className="mt-4 -mx-4 lg:mx-0" scrollRef={popularCarouselRef}>
            {EFFECT_GRADIENTS.map((g, idx) => (
              <EffectCardSkeleton key={idx} variant="effectsFeed" gradient={g} />
            ))}
          </HorizontalCarousel>
          {showPopularHint ? <p className="mt-2 text-center text-[11px] text-white/30">Swipe to explore</p> : null}
        </section>
      ) : null}

      {popularState.status === "error" ? (
        <section className="mt-10">
          <div className="rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
            <div className="text-sm font-semibold text-red-100">Could not load popular effects</div>
            <div className="mt-1 text-xs text-red-100/70">{popularState.message}</div>
            <button
              type="button"
              onClick={loadPopular}
              className="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-xs font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Retry
            </button>
          </div>
        </section>
      ) : null}

      {popularState.status === "empty" ? (
        <section className="mt-10">
          <div className="rounded-3xl border border-white/[0.07] bg-white/[0.03] p-6 text-center text-sm text-white/60">
            No effects yet.
          </div>
        </section>
      ) : null}

      {popularState.status === "success" ? (
        <section className="mt-10" id="popular">
          <div className="flex items-end justify-between gap-6">
            <div>
              <div className="flex items-center gap-2.5">
                <span className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true" />
                <h2 className="text-base font-semibold tracking-tight text-white sm:text-lg">Popular Effects</h2>
              </div>
              <p className="mt-1 text-xs text-white/45 sm:text-sm">Trending transformations loved by creators.</p>
            </div>
            {showPopularSeeAll ? (
              <Link
                href="/effects"
                className="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-white/10 bg-white/[0.03] px-3.5 py-1.5 text-xs font-semibold text-white/70 transition-all duration-200 hover:border-white/20 hover:bg-white/[0.06] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                See All
              </Link>
            ) : null}
          </div>
          <HorizontalCarousel className="mt-4 -mx-4 lg:mx-0" showRightFade scrollRef={popularCarouselRef}>
            {popularState.data.map((effect) => (
              <EffectCard key={effect.slug} variant="effectsFeed" effect={effect} onTry={() => handleOpenEffect(effect)} />
            ))}
          </HorizontalCarousel>
          {showPopularHint ? <p className="mt-2 text-center text-[11px] text-white/30">Swipe to explore</p> : null}
        </section>
      ) : null}

      {categoryOrder.length === 0 && !categoriesLoaded ? (
        <>
          {Array.from({ length: 3 }).map((_, idx) => (
            <CategoryRowSkeleton key={`category-skeleton-${idx}`} gradientIndex={idx} />
          ))}
        </>
      ) : null}
      {categoryOrder.length === 0 && categoriesLoaded && !categoriesLoading && !categoriesError ? (
        <div className="mt-6 rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
          No categories available yet.
        </div>
      ) : null}

      {categoryOrder.map((slug) => {
        const row = categoryRows[slug];
        if (!row) return null;
        return <CategoryRow key={slug} row={row} onTry={handleOpenEffect} onLoadMore={handleLoadMoreEffects} />;
      })}

      {categoriesError ? (
        <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-4 text-xs text-red-100">
          {categoriesError}
        </div>
      ) : null}
      {categoriesLoading ? <div className="mt-4 text-center text-xs text-white/50">Loading more categories…</div> : null}
      <div ref={loadMoreRef} className="h-8" />
    </>
  );
}
