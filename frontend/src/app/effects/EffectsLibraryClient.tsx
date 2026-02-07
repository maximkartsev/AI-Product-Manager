"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { ApiError, getEffects, type ApiEffect } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { IconPlay, IconSparkles } from "@/app/_components/landing/icons";
import { brand } from "@/app/_components/landing/landingData";

type EffectsState =
  | { status: "loading" }
  | { status: "success"; data: ApiEffect[] }
  | { status: "empty" }
  | { status: "error"; message: string };

type CategoryGroup = {
  id: number | null;
  slug: string;
  name: string;
  description?: string | null;
  items: ApiEffect[];
};

const EFFECT_GRADIENTS = [
  { from: "from-fuchsia-500", to: "to-cyan-400" },
  { from: "from-amber-400", to: "to-pink-500" },
  { from: "from-sky-400", to: "to-indigo-500" },
  { from: "from-lime-400", to: "to-emerald-500" },
  { from: "from-cyan-400", to: "to-blue-500" },
  { from: "from-fuchsia-500", to: "to-violet-500" },
] as const;

const FALLBACK_CATEGORY = {
  id: null,
  slug: "other",
  name: "Other",
  description: "More effects to explore.",
};

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

function sortedByPopularity(effects: ApiEffect[]): ApiEffect[] {
  const items = [...effects];
  const hasPopularity = items.some((effect) => (effect.popularity_score ?? 0) > 0);
  const hasSortOrder = items.some((effect) => (effect.sort_order ?? 0) !== 0);

  items.sort((a, b) => {
    const popA = a.popularity_score ?? 0;
    const popB = b.popularity_score ?? 0;
    if (popA !== popB) return popB - popA;

    const orderA = a.sort_order ?? 0;
    const orderB = b.sort_order ?? 0;
    if (orderA !== orderB) return orderB - orderA;

    return a.id - b.id;
  });

  if (!hasPopularity && !hasSortOrder) {
    items.sort((a, b) => hashString(a.slug) - hashString(b.slug));
  }

  return items;
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

function CarouselRow({
  id,
  title,
  icon,
  subtitle,
  seeAllHref,
  isActive,
  effects,
  onTry,
}: {
  id?: string;
  title: string;
  icon?: string | null;
  subtitle?: string;
  seeAllHref?: string;
  isActive?: boolean;
  effects: ApiEffect[];
  onTry: (effect: ApiEffect) => void;
}) {
  if (effects.length === 0) return null;
  return (
    <section className="mt-8" id={id}>
      <div className="flex items-center justify-between gap-6">
        <div className="flex items-center gap-2">
          {icon ? <span className="text-base">{icon}</span> : null}
          <div>
            <h2 className="text-sm font-semibold text-white">{title}</h2>
            {subtitle ? <p className="mt-0.5 text-[11px] text-white/50">{subtitle}</p> : null}
          </div>
        </div>
        {seeAllHref && !isActive ? (
          <Link
            href={seeAllHref}
            className="text-xs font-semibold text-fuchsia-300 transition hover:text-fuchsia-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
          >
            See All
          </Link>
        ) : null}
      </div>

      <div className="mt-3 -mx-4 overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4">
        <div className="flex gap-3">
          {effects.map((effect) => (
            <EffectCard key={effect.slug} effect={effect} onTry={() => onTry(effect)} />
          ))}
        </div>
      </div>

      {effects.length > 1 ? <p className="mt-2 text-center text-[11px] text-white/40">Swipe to explore</p> : null}
    </section>
  );
}

export default function EffectsLibraryClient() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [effectsState, setEffectsState] = useState<EffectsState>({ status: "loading" });
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let cancelled = false;
    async function run() {
      setEffectsState({ status: "loading" });
      try {
        const data = await getEffects();
        if (cancelled) return;
        const items = (data ?? []).filter((effect) => effect && effect.is_active);
        if (items.length === 0) {
          setEffectsState({ status: "empty" });
          return;
        }
        setEffectsState({ status: "success", data: items });
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError) {
          setEffectsState({ status: "error", message: err.message });
          return;
        }
        setEffectsState({ status: "error", message: "Unexpected error while loading effects." });
      }
    }

    void run();
    return () => {
      cancelled = true;
    };
  }, [reload]);

  const sortedEffects = useMemo(() => {
    if (effectsState.status !== "success") return [];
    return sortedByPopularity(effectsState.data);
  }, [effectsState]);

  const topEffects = useMemo(() => sortedEffects.slice(0, 8), [sortedEffects]);
  const activeCategorySlug = (searchParams?.get("category") ?? "").trim().toLowerCase() || null;
  const showTopEffects = !activeCategorySlug || activeCategorySlug === "popular";

  const categories = useMemo(() => {
    if (effectsState.status !== "success") return [];
    const buckets = new Map<string, CategoryGroup>();

    for (const effect of sortedEffects) {
      const category = effect.category ?? null;
      const slug = category?.slug ?? FALLBACK_CATEGORY.slug;
      const group = buckets.get(slug) ?? {
        id: category?.id ?? FALLBACK_CATEGORY.id,
        slug,
        name: category?.name ?? FALLBACK_CATEGORY.name,
        description: category?.description ?? FALLBACK_CATEGORY.description,
        items: [],
      };
      group.items.push(effect);
      buckets.set(slug, group);
    }

    const groups = Array.from(buckets.values());
    groups.sort((a, b) => {
      if (a.slug === FALLBACK_CATEGORY.slug) return 1;
      if (b.slug === FALLBACK_CATEGORY.slug) return -1;
      return a.name.localeCompare(b.name);
    });
    if (activeCategorySlug && activeCategorySlug !== "popular") {
      const match = groups.filter((group) => group.slug.toLowerCase() === activeCategorySlug);
      return match.length > 0 ? match : groups;
    }
    return groups;
  }, [effectsState, sortedEffects, activeCategorySlug]);

  const handleOpenEffect = (effect: ApiEffect) => {
    router.push(`/effects/${encodeURIComponent(effect.slug)}`);
  };

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl">
        <header className="flex items-center justify-between gap-3">
          <Link
            href="/"
            className="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-white"
            aria-label={`${brand.name} home`}
          >
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-white/10">
              <IconSparkles className="h-4 w-4 text-fuchsia-200" />
            </span>
            <span className="uppercase">{brand.name}</span>
          </Link>
          <div className="text-xs text-white/55">Effects library</div>
        </header>

        <section className="mt-6">
          <h1 className="text-2xl font-semibold tracking-tight text-white sm:text-3xl">All effects</h1>
          <p className="mt-2 text-sm text-white/60">
            Browse the most popular effects, then explore by category.
          </p>
        </section>

        {effectsState.status === "loading" ? (
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

        {effectsState.status === "error" ? (
          <section className="mt-8">
            <div className="rounded-3xl border border-red-500/25 bg-red-500/10 p-4">
              <div className="text-sm font-semibold text-red-100">Could not load effects</div>
              <div className="mt-1 text-xs text-red-100/70">{effectsState.message}</div>
              <button
                type="button"
                onClick={() => setReload((v) => v + 1)}
                className="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-xs font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                Retry
              </button>
            </div>
          </section>
        ) : null}

        {effectsState.status === "empty" ? (
          <section className="mt-8">
            <div className="rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
              No effects yet.
            </div>
          </section>
        ) : null}

        {effectsState.status === "success" ? (
          <>
            {showTopEffects ? (
              <CarouselRow
                id="popular"
                title="Popular Effects"
                icon={categoryEmoji("popular")}
                subtitle="Trending transformations loved by creators."
                effects={topEffects}
                onTry={handleOpenEffect}
              />
            ) : null}
            {categories.map((category) => (
              <CarouselRow
                key={category.slug}
                id={category.slug}
                title={category.name}
                icon={categoryEmoji(category.slug)}
                subtitle={category.description ?? undefined}
                seeAllHref={`/effects?category=${encodeURIComponent(category.slug)}`}
                isActive={activeCategorySlug === category.slug.toLowerCase()}
                effects={category.items}
                onTry={handleOpenEffect}
              />
            ))}
          </>
        ) : null}
      </div>
    </div>
  );
}
