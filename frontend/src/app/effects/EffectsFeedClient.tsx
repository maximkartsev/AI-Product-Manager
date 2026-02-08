"use client";

import { useEffect, useRef, useState, type UIEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  ApiError,
  getCategories,
  getEffectsIndex,
  type ApiCategory,
  type ApiEffect,
} from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { IconPlay } from "@/app/_components/landing/icons";

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

const EFFECT_GRADIENTS = [
  { from: "from-fuchsia-500", to: "to-cyan-400" },
  { from: "from-amber-400", to: "to-pink-500" },
  { from: "from-sky-400", to: "to-indigo-500" },
  { from: "from-lime-400", to: "to-emerald-500" },
  { from: "from-cyan-400", to: "to-blue-500" },
  { from: "from-fuchsia-500", to: "to-violet-500" },
] as const;

function gradientClass(from: string, to: string) {
  return `${from} ${to}`;
}

function hashString(value: string): number {
  let h = 0;
  for (let i = 0; i < value.length; i++) {
    h = (h * 31 + value.charCodeAt(i)) | 0;
  }
  return h;
}

function gradientForSlug(slug: string) {
  const idx = Math.abs(hashString(slug)) % EFFECT_GRADIENTS.length;
  return EFFECT_GRADIENTS[idx]!;
}

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

function EffectCard({ effect, onTry }: { effect: ApiEffect; onTry: () => void }) {
  const gradient = gradientForSlug(effect.slug);
  const g = gradientClass(gradient.from, gradient.to);
  const usesLabel = formatUses(effect) ?? (effect.is_new ? "New" : "Try it");

  return (
    <div className="snap-start">
      <button type="button" onClick={onTry} className="w-32 text-left sm:w-36">
        <div className="overflow-hidden rounded-2xl border border-white/10 bg-white/5 shadow-[0_10px_24px_rgba(0,0,0,0.25)]">
          <div className={`relative aspect-[3/4] bg-gradient-to-br ${g}`}>
            {effect.thumbnail_url ? (
              <img className="absolute inset-0 h-full w-full object-cover" src={effect.thumbnail_url} alt={effect.name} />
            ) : effect.preview_video_url ? (
              <VideoPlayer
                className="absolute inset-0 h-full w-full object-cover"
                src={effect.preview_video_url}
                autoPlay
                loop
                muted
                playsInline
                preload="metadata"
              />
            ) : null}
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
          </div>
        </div>
        <div className="mt-2">
          <div className="truncate text-xs font-semibold text-white">{effect.name}</div>
          <div className="text-[10px] text-white/50">{usesLabel}</div>
        </div>
      </button>
    </div>
  );
}

function EffectCardSkeleton({ gradient }: { gradient: { from: string; to: string } }) {
  const g = gradientClass(gradient.from, gradient.to);
  return (
    <div className="snap-start animate-pulse">
      <div className="w-32 sm:w-36">
        <div className="overflow-hidden rounded-2xl border border-white/10 bg-white/5 shadow-[0_10px_24px_rgba(0,0,0,0.25)]">
          <div className={`relative aspect-[3/4] bg-gradient-to-br ${g}`}>
            <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />
          </div>
        </div>
        <div className="mt-2">
          <div className="h-3 w-24 rounded bg-white/10" />
          <div className="mt-1 h-3 w-16 rounded bg-white/5" />
        </div>
      </div>
    </div>
  );
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
  const scrollerRef = useRef<HTMLDivElement | null>(null);

  const canLoadMore = row.page < row.totalPages && !row.loadingMore && !row.loading;

  const handleScroll = (event: UIEvent<HTMLDivElement>) => {
    if (!canLoadMore) return;
    const target = event.currentTarget;
    const threshold = 32;
    if (target.scrollLeft + target.clientWidth >= target.scrollWidth - threshold) {
      onLoadMore(row.category.slug);
    }
  };

  return (
    <section className="mt-8" id={row.category.slug}>
      <div className="flex items-center justify-between gap-6">
        <div className="flex items-center gap-2">
          <span className="text-base">{categoryEmoji(row.category.slug)}</span>
          <div>
            <h2 className="text-sm font-semibold text-white">{row.category.name}</h2>
            {row.category.description ? (
              <p className="mt-0.5 text-[11px] text-white/50">{row.category.description}</p>
            ) : null}
          </div>
        </div>
        <Link
          href={`/effects/categories/${encodeURIComponent(row.category.slug)}`}
          className="text-xs font-semibold text-fuchsia-300 transition hover:text-fuchsia-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
        >
          See All
        </Link>
      </div>

      <div
        ref={scrollerRef}
        onScroll={handleScroll}
        className="mt-3 -mx-4 overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4"
      >
        <div className="flex gap-3">
          {row.effects.length === 0 && row.loading
            ? EFFECT_GRADIENTS.map((g, idx) => <EffectCardSkeleton key={idx} gradient={g} />)
            : row.effects.map((effect) => (
                <EffectCard key={effect.slug} effect={effect} onTry={() => onTry(effect)} />
              ))}
          {row.loadingMore ? <EffectCardSkeleton gradient={EFFECT_GRADIENTS[0]} /> : null}
        </div>
      </div>

      {row.effects.length > 1 ? <p className="mt-2 text-center text-[11px] text-white/40">Swipe to explore</p> : null}
    </section>
  );
}

export default function EffectsFeedClient({ showPopularSeeAll = false }: EffectsFeedClientProps) {
  const router = useRouter();
  const [popularState, setPopularState] = useState<PopularState>({ status: "loading" });
  const [categoryOrder, setCategoryOrder] = useState<string[]>([]);
  const [categoryRows, setCategoryRows] = useState<Record<string, CategoryRowState>>({});
  const [categoriesPage, setCategoriesPage] = useState(0);
  const [categoriesTotalPages, setCategoriesTotalPages] = useState(1);
  const [categoriesLoading, setCategoriesLoading] = useState(false);
  const [categoriesError, setCategoriesError] = useState<string | null>(null);
  const loadMoreRef = useRef<HTMLDivElement | null>(null);
  const categoryRowsRef = useRef<Record<string, CategoryRowState>>({});

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
      const newSlugs = (data.items ?? [])
        .map((category) => category.slug)
        .filter((slug) => !existing[slug]);

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
    router.push(`/effects/${encodeURIComponent(effect.slug)}`);
  };

  return (
    <>
      {popularState.status === "loading" ? (
        <section className="mt-8">
          <div className="flex items-end justify-between gap-6">
            <div>
              <h2 className="text-lg font-semibold tracking-tight text-white">Top effects</h2>
              <p className="mt-1 text-xs text-white/50">Loading the most popular picks...</p>
            </div>
          </div>
          <div className="mt-4 -mx-4 overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4">
            <div className="flex gap-3">
              {EFFECT_GRADIENTS.map((g, idx) => (
                <EffectCardSkeleton key={idx} gradient={g} />
              ))}
            </div>
          </div>
        </section>
      ) : null}

      {popularState.status === "error" ? (
        <section className="mt-8">
          <div className="rounded-3xl border border-red-500/25 bg-red-500/10 p-4">
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
        <section className="mt-8">
          <div className="rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
            No effects yet.
          </div>
        </section>
      ) : null}

      {popularState.status === "success" ? (
        <section className="mt-8" id="popular">
          <div className="flex items-center justify-between gap-6">
            <div className="flex items-center gap-2">
              <span className="text-base">{categoryEmoji("popular")}</span>
              <div>
                <h2 className="text-sm font-semibold text-white">Popular Effects</h2>
                <p className="mt-0.5 text-[11px] text-white/50">Trending transformations loved by creators.</p>
              </div>
            </div>
            {showPopularSeeAll ? (
              <Link
                href="/effects"
                className="text-xs font-semibold text-fuchsia-300 transition hover:text-fuchsia-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                See All
              </Link>
            ) : null}
          </div>
          <div className="mt-3 -mx-4 overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4">
            <div className="flex gap-3">
              {popularState.data.map((effect) => (
                <EffectCard key={effect.slug} effect={effect} onTry={() => handleOpenEffect(effect)} />
              ))}
            </div>
          </div>
          {popularState.data.length > 1 ? (
            <p className="mt-2 text-center text-[11px] text-white/40">Swipe to explore</p>
          ) : null}
        </section>
      ) : null}

      {categoryOrder.length === 0 && categoriesLoading ? (
        <div className="mt-6 text-center text-xs text-white/50">Loading categories…</div>
      ) : null}
      {categoryOrder.length === 0 && !categoriesLoading && !categoriesError ? (
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
