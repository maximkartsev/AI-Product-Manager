"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, getEffectsIndex, type ApiEffect } from "@/lib/api";
import EffectGridCard from "@/app/effects/_components/EffectGridCard";
import useUiGuards from "@/components/guards/useUiGuards";

type EffectsState = {
  items: ApiEffect[];
  page: number;
  totalPages: number;
  loading: boolean;
  loadingMore: boolean;
  error?: string | null;
};

export default function EffectsGridClient() {
  const { requireAuthForNavigation } = useUiGuards();
  const [state, setState] = useState<EffectsState>({
    items: [],
    page: 0,
    totalPages: 1,
    loading: true,
    loadingMore: false,
  });
  const loadMoreRef = useRef<HTMLDivElement | null>(null);
  const handleOpenEffect = (effect: ApiEffect) => {
    requireAuthForNavigation(`/effects/${encodeURIComponent(effect.slug)}`);
  };

  const loadEffects = async (page: number) => {
    setState((prev) => ({
      ...prev,
      loading: page === 1,
      loadingMore: page > 1,
      error: null,
    }));
    try {
      const data = await getEffectsIndex({
        page,
        perPage: 12,
        order: "id:desc",
      });
      setState((prev) => ({
        ...prev,
        items: page === 1 ? data.items : [...prev.items, ...data.items],
        page: data.page,
        totalPages: data.totalPages,
        loading: false,
        loadingMore: false,
      }));
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unable to load effects.";
      setState((prev) => ({
        ...prev,
        loading: false,
        loadingMore: false,
        error: message,
      }));
    }
  };

  useEffect(() => {
    void loadEffects(1);
  }, []);

  useEffect(() => {
    const el = loadMoreRef.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0];
        if (!entry?.isIntersecting) return;
        if (state.loading || state.loadingMore) return;
        if (state.page >= state.totalPages) return;
        if (state.error) return;
        void loadEffects(state.page + 1);
      },
      { rootMargin: "200px" },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [state.loading, state.loadingMore, state.page, state.totalPages, state.error]);

  return (
    <section className="mt-6">
      {state.error ? (
        <div className="rounded-3xl border border-red-500/25 bg-red-500/10 p-4 text-xs text-red-100">
          {state.error}
        </div>
      ) : null}
      {state.loading && state.items.length === 0 ? (
        <div className="text-center text-xs text-white/50">Loading effects…</div>
      ) : null}
      {state.items.length === 0 && !state.loading ? (
        <div className="rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
          No effects yet.
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {state.items.map((effect) => (
            <EffectGridCard
              key={effect.slug}
              effect={effect}
              onOpen={() => handleOpenEffect(effect)}
            />
          ))}
        </div>
      )}
      {state.loadingMore ? <div className="mt-4 text-center text-xs text-white/50">Loading more…</div> : null}
      <div ref={loadMoreRef} className="h-8" />
    </section>
  );
}

