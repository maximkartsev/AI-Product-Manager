"use client";

import {
  ApiError,
  getEffectsIndex,
  getPublicGallery,
  type ApiEffect,
  type GalleryVideo,
} from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import useEffectUploadStart from "@/lib/useEffectUploadStart";
import useUiGuards from "@/components/guards/useUiGuards";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { features, hero, trustBadges, type Effect, type GalleryItem } from "./landingData";
import { IconArrowRight, IconBolt, IconGallery, IconSparkles, IconWand } from "./icons";
import HorizontalCarousel from "@/components/ui/HorizontalCarousel";
import { EffectCard, EffectCardSkeleton } from "@/components/cards/EffectCard";
import { PublicGalleryCard, PublicGalleryCardSkeleton } from "@/components/cards/PublicGalleryCard";
import { EFFECT_GRADIENTS, gradientClass, gradientForSlug } from "@/lib/gradients";

type LandingEffect = Effect & {
  slug: string;
  preview_video_url?: string | null;
  credits_cost?: number | null;
};

type EffectsState =
  | { status: "loading" }
  | { status: "success"; data: LandingEffect[] }
  | { status: "empty" }
  | { status: "error"; message: string };

type PublicGalleryState =
  | { status: "loading" }
  | { status: "success"; data: GalleryItem[] }
  | { status: "empty" }
  | { status: "error"; message: string };


function taglineForDescription(description?: string | null): string {
  const d = (description ?? "").trim();
  if (!d) return "One‑click AI video effect";

  // Prefer the first sentence/line for compact cards.
  const firstSentence = d.split(/\r?\n/)[0]?.split(/(?<=[.!?])\s/)[0] ?? d;
  return firstSentence.trim() || "One‑click AI video effect";
}

function toLandingEffect(effect: ApiEffect): LandingEffect {
  return {
    id: effect.slug,
    slug: effect.slug,
    name: effect.name,
    tagline: taglineForDescription(effect.description),
    type: effect.type ?? null,
    is_premium: effect.is_premium,
    thumbnail_url: effect.thumbnail_url ?? null,
    preview_video_url: effect.preview_video_url ?? null,
    badge: undefined,
    stats: { uses: "Tokens" },
    gradient: gradientForSlug(effect.slug),
    credits_cost: effect.credits_cost ?? null,
  };
}

function toLandingGalleryItem(item: GalleryVideo): GalleryItem {
  const title = (item.title ?? "").trim() || "Untitled";
  const effectName = item.effect?.name ?? "AI Effect";
  const seed = item.effect?.slug ?? String(item.id);

  return {
    id: String(item.id),
    title,
    effect: effectName,
    gradient: gradientForSlug(seed),
    thumbnail_url: item.thumbnail_url ?? null,
    processed_file_url: item.processed_file_url ?? null,
    effect_slug: item.effect?.slug ?? null,
    effect_type: item.effect?.type ?? null,
  };
}

function pickFeaturedEffect(effects: LandingEffect[]): LandingEffect | null {
  if (!effects.length) return null;
  // TODO: Replace with usage-frequency ranking once available.
  const idx = Math.floor(Math.random() * effects.length);
  return effects[idx] ?? null;
}

function AvatarStack() {
  const avatars = useMemo(() => {
    return [
      "from-fuchsia-500 to-violet-500",
      "from-cyan-400 to-blue-500",
      "from-amber-400 to-pink-500",
      "from-lime-400 to-emerald-500",
      "from-sky-400 to-indigo-500",
    ];
  }, []);

  return (
    <div className="flex -space-x-2">
      {avatars.map((g, idx) => (
        <div
          key={g}
          className={`h-8 w-8 rounded-full border border-black/40 bg-gradient-to-br ${g} shadow-sm`}
          style={{ zIndex: avatars.length - idx }}
          aria-hidden="true"
        />
      ))}
    </div>
  );
}

function FeatureRow({
  title,
  description,
  icon,
}: {
  title: string;
  description: string;
  icon: ReactNode;
}) {
  return (
    <div className="flex gap-4 rounded-3xl border border-white/10 bg-white/5 p-4">
      <div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/25 to-violet-500/20 text-fuchsia-200">
        {icon}
      </div>
      <div className="min-w-0">
        <div className="text-sm font-semibold text-white">{title}</div>
        <div className="mt-1 text-sm leading-6 text-white/70">{description}</div>
      </div>
    </div>
  );
}

export default function LandingHome() {
  const router = useRouter();
  const { requireAuth, requireAuthForNavigation, ensureTokens } = useUiGuards();
  const [effectsState, setEffectsState] = useState<EffectsState>({ status: "loading" });
  const [effectsReload, setEffectsReload] = useState(0);
  const [featuredEffect, setFeaturedEffect] = useState<LandingEffect | null>(null);
  const [galleryState, setGalleryState] = useState<PublicGalleryState>({ status: "loading" });
  const [galleryReload, setGalleryReload] = useState(0);
  const {
    fileInputRef,
    startUpload,
    onFileSelected,
    clearUploadError,
  } = useEffectUploadStart({
    slug: null,
  });

  const resolveCreditsCost = (raw?: number | null) => Math.max(0, Math.ceil(Number(raw ?? 0)));

  const handleEffectTry = async (effect: LandingEffect) => {
    if (effect.type === "configurable") {
      requireAuthForNavigation(`/effects/${encodeURIComponent(effect.slug)}`);
      return;
    }
    if (!requireAuth()) return;
    const creditsCost = resolveCreditsCost(effect.credits_cost);
    const okTokens = await ensureTokens(creditsCost);
    if (!okTokens) return;
    clearUploadError();
    const result = startUpload(effect.slug);
    if (!result.ok && result.reason === "unauthenticated") {
      requireAuth();
    }
  };

  const handleDoSameClick = async () => {
    if (!featuredEffect) return;
    if (featuredEffect.type === "configurable") {
      requireAuthForNavigation(`/effects/${encodeURIComponent(featuredEffect.slug)}`);
      return;
    }
    if (!requireAuth()) return;
    const creditsCost = resolveCreditsCost(featuredEffect.credits_cost);
    const okTokens = await ensureTokens(creditsCost);
    if (!okTokens) return;
    clearUploadError();
    const result = startUpload(featuredEffect.slug);
    if (!result.ok && result.reason === "unauthenticated") {
      requireAuth();
    }
  };

  const handleGalleryTry = (item: GalleryItem) => {
    if (item.effect_type === "configurable") {
      requireAuthForNavigation(`/explore/${encodeURIComponent(item.id)}`);
      return;
    }
    if (item.effect_slug) {
      clearUploadError();
      if (!requireAuth()) return;
      const result = startUpload(item.effect_slug);
      if (!result.ok && result.reason === "unauthenticated") {
        requireAuth();
      }
      return;
    }
    requireAuthForNavigation(`/explore/${encodeURIComponent(item.id)}`);
  };

  const handleGalleryOpen = (item: GalleryItem) => {
    requireAuthForNavigation(`/explore/${encodeURIComponent(item.id)}`);
  };

  useEffect(() => {
    let cancelled = false;

    async function run() {
      setEffectsState({ status: "loading" });

      try {
        const data = await getEffectsIndex({ perPage: 8, order: "popularity_score:desc" });
        if (cancelled) return;

        const items = (data.items ?? []).filter((e) => e && e.is_active).map(toLandingEffect);
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
  }, [effectsReload]);

  useEffect(() => {
    if (effectsState.status !== "success") {
      setFeaturedEffect(null);
      return;
    }
    setFeaturedEffect(pickFeaturedEffect(effectsState.data));
  }, [effectsState.status, effectsState.data]);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      setGalleryState({ status: "loading" });
      try {
        const data = await getPublicGallery({ perPage: 4 });
        if (cancelled) return;

        const items = (data.items ?? []).map(toLandingGalleryItem);
        if (items.length === 0) {
          setGalleryState({ status: "empty" });
          return;
        }

        setGalleryState({ status: "success", data: items });
      } catch (err) {
        if (cancelled) return;

        if (err instanceof ApiError) {
          setGalleryState({ status: "error", message: err.message });
          return;
        }

        setGalleryState({ status: "error", message: "Unexpected error while loading gallery." });
      }
    }

    void run();

    return () => {
      cancelled = true;
    };
  }, [galleryReload]);

  const heroEffectLabel = featuredEffect
    ? featuredEffect.name.toLowerCase().includes("effect")
      ? featuredEffect.name
      : `${featuredEffect.name} Effect`
    : hero.effectLabel;
  const heroEffectDescription = featuredEffect?.tagline ?? hero.effectDescription;

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div className="relative mx-auto w-full max-w-md sm:max-w-xl lg:max-w-4xl">
        <main className="pb-32">
          <section className="relative">
            <div className="relative w-full overflow-hidden md:mx-auto md:mt-6 md:max-w-md">
              <div className="absolute inset-0">
                {featuredEffect?.preview_video_url ? (
                  <VideoPlayer
                    className="absolute inset-0 h-full w-full scale-110 object-cover blur-2xl opacity-60"
                    src={featuredEffect.preview_video_url}
                    muted
                    loop
                    autoPlay
                    playsInline
                    preload="metadata"
                  />
                ) : featuredEffect?.thumbnail_url ? (
                  <img
                    className="absolute inset-0 h-full w-full scale-110 object-cover blur-2xl opacity-60"
                    src={featuredEffect.thumbnail_url}
                    alt=""
                    aria-hidden="true"
                  />
                ) : null}
                <div className="absolute inset-0 bg-gradient-to-b from-black/45 via-black/25 to-black/70" />
              </div>

              <div className="relative aspect-[9/16] w-full overflow-hidden bg-black/40 shadow-[0_20px_60px_rgba(0,0,0,0.35)]">
                {featuredEffect?.preview_video_url ? (
                  <VideoPlayer
                    className="absolute inset-0 h-full w-full object-cover"
                    src={featuredEffect.preview_video_url}
                    muted
                    loop
                    autoPlay
                    playsInline
                    preload="metadata"
                  />
                ) : featuredEffect?.thumbnail_url ? (
                  <img
                    className="absolute inset-0 h-full w-full object-cover"
                    src={featuredEffect.thumbnail_url}
                    alt={heroEffectLabel}
                  />
                ) : null}
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(236,72,153,0.35),transparent_55%),radial-gradient(circle_at_80%_40%,rgba(34,211,238,0.28),transparent_58%),radial-gradient(circle_at_40%_90%,rgba(99,102,241,0.25),transparent_55%)]" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/35 via-black/20 to-black/85" />

                <div className="absolute inset-x-0 top-0 z-10 px-4 pt-16">
                  <div className="mx-auto flex w-full max-w-md justify-center">
                    <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/90 backdrop-blur-sm">
                      <IconSparkles className="h-4 w-4 text-fuchsia-200" />
                      {hero.badge}
                    </div>
                  </div>
                </div>

                <div className="absolute bottom-4 left-4 right-4">
                  <div className="flex items-start gap-3 rounded-2xl border border-white/10 bg-black/45 px-3 py-2 backdrop-blur-sm">
                    <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white/10">
                      <IconSparkles className="h-4 w-4 text-fuchsia-200" />
                    </span>
                    <div className="min-w-0">
                      <div className="truncate text-sm font-semibold text-white">{heroEffectLabel}</div>
                      <div className="mt-0.5 line-clamp-2 text-xs leading-5 text-white/70">
                        {heroEffectDescription}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div className="px-4 pt-4 md:mx-auto md:max-w-md">
              <button
                type="button"
                onClick={handleDoSameClick}
                className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300"
              >
                <IconWand className="h-5 w-5" />
                Do the Same
              </button>
            </div>

            <div className="px-4 pt-6 text-center">
              <h1 className="mt-4 whitespace-pre-line text-3xl font-semibold leading-tight tracking-tight text-white sm:text-4xl">
                {hero.headline}
              </h1>
              <p className="mt-3 text-sm leading-6 text-white/70">{hero.description}</p>

              <div className="mt-5 flex items-center justify-center gap-3">
                <AvatarStack />
                <div className="min-w-0">
                  <div className="text-sm font-semibold text-white">{hero.socialProof.headline}</div>
                  <div className="text-xs text-white/50">Fast, fun, and built for vertical video.</div>
                </div>
              </div>
            </div>
          </section>

          <section className="mt-10 px-4">
            <div className="flex items-end justify-between gap-6">
              <div>
                <h2 className="text-lg font-semibold tracking-tight text-white">Popular Effects</h2>
                <p className="mt-1 text-xs text-white/50">Trending transformations loved by creators</p>
              </div>
              <Link
                href="/effects"
                className="inline-flex items-center gap-1 text-xs font-semibold text-white/70 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                View all <IconArrowRight className="h-4 w-4" />
              </Link>
            </div>

            {effectsState.status === "error" ? (
              <div className="mt-4 rounded-3xl border border-red-500/25 bg-red-500/10 p-4">
                <div className="text-sm font-semibold text-red-100">Couldn&apos;t load effects</div>
                <div className="mt-1 text-xs text-red-100/70">{effectsState.message}</div>
                <button
                  type="button"
                  onClick={() => setEffectsReload((v) => v + 1)}
                  className="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-xs font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                >
                  Retry
                </button>
              </div>
            ) : null}

            {effectsState.status === "empty" ? (
              <div className="mt-4 rounded-3xl border border-white/10 bg-white/5 p-4 text-center text-sm text-white/60">
                No effects yet.
              </div>
            ) : (
              <HorizontalCarousel className="mt-4 -mx-4" showRightFade>
                {effectsState.status === "success"
                  ? effectsState.data.map((effect) => (
                      <EffectCard
                        key={effect.slug}
                        variant="landingPopular"
                        effect={effect}
                        onTry={() => handleEffectTry(effect)}
                      />
                    ))
                  : EFFECT_GRADIENTS.slice(0, 4).map((g, idx) => (
                      <EffectCardSkeleton key={idx} variant="landingPopular" gradient={g} />
                    ))}
              </HorizontalCarousel>
            )}

            {effectsState.status === "success" && effectsState.data.length > 1 ? (
              <p className="mt-3 text-center text-xs text-white/40">Swipe to explore more effects →</p>
            ) : null}
          </section>

          <section className="mt-12 px-4">
            <div className="flex items-center justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold tracking-tight text-white">Public Gallery</h2>
                <p className="mt-1 text-xs text-white/50">See what creators are making</p>
              </div>
              <button
                type="button"
                onClick={() => router.push("/explore")}
                className="inline-flex items-center gap-1 text-xs font-semibold text-white/70 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                View all <IconArrowRight className="h-4 w-4" />
              </button>
            </div>

            {galleryState.status === "error" ? (
              <div className="mt-4 rounded-3xl border border-red-500/25 bg-red-500/10 p-4">
                <div className="text-sm font-semibold text-red-100">Couldn&apos;t load gallery</div>
                <div className="mt-1 text-xs text-red-100/70">{galleryState.message}</div>
                <button
                  type="button"
                  onClick={() => setGalleryReload((v) => v + 1)}
                  className="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-xs font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                >
                  Retry
                </button>
              </div>
            ) : null}

            {galleryState.status === "empty" ? (
              <div className="mt-4 rounded-3xl border border-white/10 bg-white/5 p-4 text-center text-sm text-white/60">
                No public videos yet.
              </div>
            ) : (
              <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {galleryState.status === "success"
                  ? galleryState.data.map((item) => (
                      <PublicGalleryCard
                        key={item.id}
                        variant="landing"
                        item={item}
                        onOpen={() => handleGalleryOpen(item)}
                        onTry={() => handleGalleryTry(item)}
                      />
                    ))
                  : EFFECT_GRADIENTS.slice(0, 4).map((g, idx) => (
                      <PublicGalleryCardSkeleton key={idx} variant="landing" gradient={g} />
                    ))}
              </div>
            )}

            <div className="mt-6 rounded-3xl border border-white/10 bg-white/5 p-4 text-center">
              <p className="text-sm text-white/70">Explore the full gallery and discover new effects.</p>
              <button
                type="button"
                onClick={() => router.push("/explore")}
                className="mt-3 inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                <IconGallery className="h-5 w-5" />
                Open Explore
              </button>
            </div>
          </section>

          <section className="mt-12 px-4 pb-10">
            <h2 className="text-2xl font-semibold leading-tight tracking-tight text-white sm:text-3xl">
              Create like a pro, no experience required
            </h2>
            <p className="mt-2 text-sm leading-6 text-white/70">
              Our AI does the heavy lifting so you can focus on being creative.
            </p>

            <div className="mt-6 grid gap-3">
              {features.map((f) => {
                const icon =
                  f.id === "instant-processing" ? (
                    <IconBolt className="h-6 w-6" />
                  ) : f.id === "one-click-effects" ? (
                    <IconWand className="h-6 w-6" />
                  ) : f.id === "viral-ready" ? (
                    <IconArrowRight className="h-6 w-6" />
                  ) : (
                    <IconSparkles className="h-6 w-6" />
                  );

                return <FeatureRow key={f.id} title={f.title} description={f.description} icon={icon} />;
              })}
            </div>

            <div className="mt-8 overflow-hidden rounded-3xl border border-white/10 bg-white/5">
              <div className="relative p-4">
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(236,72,153,0.22),transparent_55%),radial-gradient(circle_at_80%_50%,rgba(99,102,241,0.16),transparent_60%)]" />
                <div className="relative">
                  <div className="text-sm font-semibold text-white">Join over 1 million creators making viral videos</div>
                  <div className="mt-2 flex flex-wrap gap-2">
                    {trustBadges.map((t) => (
                      <span
                        key={t}
                        className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-black/25 px-3 py-1 text-xs font-medium text-white/70"
                      >
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" aria-hidden="true" />
                        {t}
                      </span>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          </section>
        </main>

      </div>

    </div>
  );
}

