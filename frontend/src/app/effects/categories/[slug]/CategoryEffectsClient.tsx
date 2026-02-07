"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ApiError, getCategory, getEffectsIndex, type ApiCategory, type ApiEffect } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { IconPlay, IconSparkles } from "@/app/_components/landing/icons";
import { brand } from "@/app/_components/landing/landingData";

const EFFECT_GRADIENTS = [
  { from: "from-fuchsia-500", to: "to-cyan-400" },
  { from: "from-amber-400", to: "to-pink-500" },
  { from: "from-sky-400", to: "to-indigo-500" },
  { from: "from-lime-400", to: "to-emerald-500" },
  { from: "from-cyan-400", to: "to-blue-500" },
  { from: "from-fuchsia-500", to: "to-violet-500" },
] as const;

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

function gradientClass(from: string, to: string) {
  return `${from} ${to}`;
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

function CategoryEffectCard({ effect, onOpen }: { effect: ApiEffect; onOpen: () => void }) {
  const gradient = gradientForSlug(effect.slug);
  const g = gradientClass(gradient.from, gradient.to);
  const usesLabel = formatUses(effect) ?? (effect.is_new ? "New" : "Try it");

  return (
    <button
      type="button"
      onClick={onOpen}
      className="group overflow-hidden rounded-2xl border border-white/10 bg-white/5 text-left shadow-[0_10px_24px_rgba(0,0,0,0.25)] transition hover:border-white/20"
    >
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
        <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/15 to-black/70" />
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
      <div className="p-3">
        <div className="truncate text-xs font-semibold text-white">{effect.name}</div>
        <div className="text-[10px] text-white/50">{usesLabel}</div>
      </div>
    </button>
  );
}

export default function CategoryEffectsClient({ slug }: { slug: string }) {
  const router = useRouter();
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
        <header className="flex items-center justify-between gap-3">
          <Link
            href="/effects"
            className="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-white"
            aria-label={`${brand.name} effects`}
          >
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-white/10">
              <IconSparkles className="h-4 w-4 text-fuchsia-200" />
            </span>
            <span className="uppercase">{brand.name}</span>
          </Link>
          <button
            type="button"
            onClick={() => router.push("/effects")}
            className="text-xs font-semibold text-white/70"
          >
            Back to all
          </button>
        </header>

        <section className="mt-6">
          <h1 className="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{title}</h1>
          {description ? <p className="mt-2 text-sm text-white/60">{description}</p> : null}
        </section>

        {categoryState.status === "error" ? (
          <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-4 text-xs text-red-100">
            {categoryState.message}
          </div>
        ) : null}

        {effectsState.loading && effectsState.items.length === 0 ? (
          <div className="mt-6 text-center text-xs text-white/50">Loading effects…</div>
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
              <CategoryEffectCard
                key={effect.slug}
                effect={effect}
                onOpen={() => router.push(`/effects/${encodeURIComponent(effect.slug)}`)}
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
