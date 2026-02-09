"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError, getCategory, getEffectsIndex, type ApiCategory, type ApiEffect } from "@/lib/api";
import EffectGridCard from "@/app/effects/_components/EffectGridCard";
import useUiGuards from "@/components/guards/useUiGuards";
import { EffectCardSkeleton } from "@/components/cards/EffectCard";
import { EFFECT_GRADIENTS } from "@/lib/gradients";

type CategoryState =
  | { status: "loading" }
  | { status: "success"; data: ApiCategory }
  | { status: "error"; message: string };

type EffectsState = {
  items: ApiEffect[];
  page: number;
  totalPages: number;
  loading: boolean;
  loadingMore: boolean;
  error?: string | null;
};

export default function CategoryEffectsClient({ slug }: { slug: string }) {
  const router = useRouter();
  const { requireAuthForNavigation } = useUiGuards();
  const [categoryState, setCategoryState] = useState<CategoryState>({ status: "loading" });
  const [effectsState, setEffectsState] = useState<EffectsState>({
    items: [],
    page: 0,
    totalPages: 1,
    loading: true,
    loadingMore: false,
  });
  const loadMoreRef = useRef<HTMLDivElement | null>(null);

  const loadCategory = async () => {
    setCategoryState({ status: "loading" });
    try {
      const data = await getCategory(slug);
      setCategoryState({ status: "success", data });
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unable to load category.";
      setCategoryState({ status: "error", message });
    }
  };

  const loadEffects = async (page: number) => {
    setEffectsState((prev) => ({
      ...prev,
      loading: page === 1,
      loadingMore: page > 1,
      error: null,
    }));
    try {
      const data = await getEffectsIndex({
        page,
        perPage: 10,
        order: "id:desc",
        category: slug,
      });
      setEffectsState((prev) => ({
        ...prev,
        items: page === 1 ? data.items : [...prev.items, ...data.items],
        page: data.page,
        totalPages: data.totalPages,
        loading: false,
        loadingMore: false,
      }));
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unable to load effects.";
      setEffectsState((prev) => ({
        ...prev,
        loading: false,
        loadingMore: false,
        error: message,
      }));
    }
  };

  useEffect(() => {
    void loadCategory();
    void loadEffects(1);
  }, [slug]);

  useEffect(() => {
    const el = loadMoreRef.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0];
        if (!entry?.isIntersecting) return;
        if (effectsState.loadingMore) return;
        if (effectsState.page >= effectsState.totalPages) return;
        void loadEffects(effectsState.page + 1);
      },
      { rootMargin: "200px" },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [effectsState.loadingMore, effectsState.page, effectsState.totalPages]);

  const title =
    categoryState.status === "success" ? categoryState.data.name : slug.replace(/-/g, " ");
  const description = categoryState.status === "success" ? categoryState.data.description : null;

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl">
        <section className="mt-4">
          <div className="flex items-center justify-between gap-3">
            <h1 className="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{title}</h1>
            <button
              type="button"
              onClick={() => router.push("/effects")}
              className="text-xs font-semibold text-white/70"
            >
              Back to all
            </button>
          </div>
          {description ? <p className="mt-2 text-sm text-white/60">{description}</p> : null}
        </section>

        {categoryState.status === "error" ? (
          <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-4 text-xs text-red-100">
            {categoryState.message}
          </div>
        ) : null}

        {effectsState.loading && effectsState.items.length === 0 ? (
          <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {Array.from({ length: 8 }).map((_, idx) => (
              <EffectCardSkeleton
                key={idx}
                variant="effectsGrid"
                gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
              />
            ))}
          </div>
        ) : null}

        {effectsState.error ? (
          <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-4 text-xs text-red-100">
            {effectsState.error}
          </div>
        ) : null}

        {effectsState.items.length === 0 && !effectsState.loading ? (
          <div className="mt-6 rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
            No effects yet.
          </div>
        ) : (
          <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {effectsState.items.map((effect) => (
              <EffectGridCard
                key={effect.slug}
                effect={effect}
                onOpen={() => requireAuthForNavigation(`/effects/${encodeURIComponent(effect.slug)}`)}
              />
            ))}
          </div>
        )}

        {effectsState.loadingMore ? <div className="mt-4 text-center text-xs text-white/50">Loading more…</div> : null}
        <div ref={loadMoreRef} className="h-8" />
      </div>
    </div>
  );
}
