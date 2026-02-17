"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, getEffectsIndex, type ApiEffect } from "@/lib/api";
import EffectGridCard from "@/app/effects/_components/EffectGridCard";
import useUiGuards from "@/components/guards/useUiGuards";
import { EffectCardSkeleton } from "@/components/cards/EffectCard";
import { EFFECT_GRADIENTS } from "@/lib/gradients";
import { IconSparkles } from "@/app/_components/landing/icons";

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
    <section className="relative mt-8">
      {/* Ambient glow */}
      <div
        className="pointer-events-none absolute -top-12 left-1/2 h-32 w-64 -translate-x-1/2 rounded-full bg-violet-600/[0.05] blur-[70px]"
        aria-hidden="true"
      />

      {state.error ? (
        <div className="rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
          <div className="text-sm font-semibold text-red-100">Could not load effects</div>
          <div className="mt-1 text-xs text-red-100/60">{state.error}</div>
          <button
            type="button"
            onClick={() => void loadEffects(1)}
            className="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-xs font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
          >
            Retry
          </button>
        </div>
      ) : null}
      {state.loading && state.items.length === 0 ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, idx) => (
            <EffectCardSkeleton
              key={idx}
              variant="effectsGrid"
              gradient={EFFECT_GRADIENTS[idx % EFFECT_GRADIENTS.length]!}
            />
          ))}
        </div>
      ) : state.items.length === 0 && !state.loading ? (
        <div className="rounded-3xl border border-white/[0.07] bg-white/[0.03] p-8 text-center">
          <div className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/20 to-violet-500/15">
            <IconSparkles className="h-6 w-6 text-white/30" />
          </div>
          <div className="text-sm font-medium text-white/50">No effects yet</div>
          <div className="mt-1 text-xs text-white/30">Check back soon for new creations.</div>
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
      {state.loadingMore ? (
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
    </section>
  );
}
