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
import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { features, hero, trustBadges, type Effect, type GalleryItem } from "./landingData";
import { IconArrowRight, IconBolt, IconGallery, IconSparkles, IconWand } from "./icons";
import HorizontalCarousel from "@/components/ui/HorizontalCarousel";
import useCarouselScrollHint from "@/components/ui/useCarouselScrollHint";
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

const FEATURE_ICON_COLORS: Record<string, { bg: string; text: string }> = {
  "instant-processing": { bg: "from-amber-500/25 to-orange-500/20", text: "text-amber-200" },
  "one-click-effects": { bg: "from-fuchsia-500/25 to-violet-500/20", text: "text-fuchsia-200" },
  "viral-ready": { bg: "from-cyan-500/25 to-blue-500/20", text: "text-cyan-200" },
  "upgrade-pro": { bg: "from-emerald-500/25 to-teal-500/20", text: "text-emerald-200" },
};

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
    <div className="flex -space-x-2.5">
      {avatars.map((g, idx) => (
        <div
          key={g}
          className={`h-9 w-9 rounded-full border-2 border-[#05050a] bg-gradient-to-br ${g} shadow-md`}
          style={{ zIndex: avatars.length - idx }}
          aria-hidden="true"
        />
      ))}
    </div>
  );
}

function SectionHeader({
  title,
  subtitle,
  action,
}: {
  title: string;
  subtitle: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex items-end justify-between gap-6">
      <div>
        <div className="flex items-center gap-2.5">
          <span className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500" aria-hidden="true" />
          <h2 className="text-xl font-semibold tracking-tight text-white sm:text-2xl">{title}</h2>
        </div>
        <p className="mt-1.5 text-xs text-white/50 sm:text-sm">{subtitle}</p>
      </div>
      {action}
    </div>
  );
}

function FeatureRow({
  title,
  description,
  icon,
  colors,
}: {
  title: string;
  description: string;
  icon: ReactNode;
  colors: { bg: string; text: string };
}) {
  return (
    <div className="group flex gap-4 rounded-3xl border border-white/[0.07] bg-white/[0.03] p-4 transition-all duration-300 hover:border-white/15 hover:bg-white/[0.06] hover:shadow-[0_8px_32px_rgba(0,0,0,0.3)]">
      <div className={`grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br ${colors.bg} ${colors.text} transition-transform duration-300 group-hover:scale-105`}>
        {icon}
      </div>
      <div className="min-w-0">
        <div className="text-sm font-semibold text-white">{title}</div>
        <div className="mt-1 text-sm leading-6 text-white/60">{description}</div>
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
  const effectsCarouselRef = useRef<HTMLDivElement | null>(null);
  const effectsCount = effectsState.status === "success" ? effectsState.data.length : 0;
  const showEffectsHint = useCarouselScrollHint({
    scrollRef: effectsCarouselRef,
    isLoading: effectsState.status !== "success" && effectsState.status !== "empty",
    deps: [effectsCount],
  });
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
  }, [effectsState.status, effectsState.status === "success" ? effectsState.data : null]);

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
    <div className="noise-overlay min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div className="relative mx-auto w-full max-w-md sm:max-w-xl lg:max-w-4xl">
        <main className="pb-32">
          {/* ── Hero Section ── */}
          <section className="relative">
            {/* Ambient background glows */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
              <div
                className="absolute -left-32 top-20 h-72 w-72 rounded-full bg-fuchsia-600/20 blur-[100px]"
                style={{ animation: "glow-drift 12s ease-in-out infinite" }}
              />
              <div
                className="absolute -right-24 top-48 h-56 w-56 rounded-full bg-violet-600/15 blur-[80px]"
                style={{ animation: "glow-drift-reverse 14s ease-in-out infinite" }}
              />
              <div
                className="absolute left-1/4 top-[60%] h-48 w-48 rounded-full bg-cyan-500/10 blur-[90px]"
                style={{ animation: "glow-drift 16s ease-in-out infinite 2s" }}
              />
            </div>

            <div className="landing-entrance relative w-full overflow-hidden rounded-b-[2rem] md:mx-auto md:mt-6 md:max-w-md md:rounded-[2rem]">
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

              <div className="relative aspect-[9/16] w-full overflow-hidden bg-black/40 shadow-[0_20px_80px_rgba(0,0,0,0.5)]">
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
                  <div className="landing-entrance landing-entrance-d1 mx-auto flex w-full max-w-md justify-center">
                    <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/90 shadow-[0_4px_20px_rgba(0,0,0,0.3)] backdrop-blur-md">
                      <IconSparkles className="h-4 w-4 text-fuchsia-200" />
                      {hero.badge}
                    </div>
                  </div>
                </div>

                <div className="absolute bottom-4 left-4 right-4 landing-entrance landing-entrance-d2">
                  <div className="flex items-start gap-3 rounded-2xl border border-white/[0.08] bg-black/50 px-3.5 py-2.5 shadow-[0_8px_32px_rgba(0,0,0,0.4)] backdrop-blur-xl">
                    <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-fuchsia-500/30 to-violet-500/25">
                      <IconSparkles className="h-4 w-4 text-fuchsia-200" />
                    </span>
                    <div className="min-w-0">
                      <div className="truncate text-sm font-semibold text-white">{heroEffectLabel}</div>
                      <div className="mt-0.5 line-clamp-2 text-xs leading-5 text-white/60">
                        {heroEffectDescription}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div className="landing-entrance landing-entrance-d3 sticky bottom-8 z-20 px-4 pt-4 md:mx-auto md:max-w-md">
              <button
                type="button"
                onClick={handleDoSameClick}
                className="inline-flex h-13 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_40px_rgba(236,72,153,0.3)] transition-all duration-200 hover:scale-[1.02] hover:shadow-[0_16px_48px_rgba(236,72,153,0.4)] active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300"
                style={{ animation: "pulse-ring 3s ease-in-out infinite" }}
              >
                <IconWand className="h-5 w-5" />
                Do the Same
              </button>
            </div>

            <div className="landing-entrance landing-entrance-d4 px-4 pt-8 text-center">
              <h1 className="mt-4 whitespace-pre-line text-3xl font-bold leading-[1.15] tracking-tight text-white sm:text-4xl lg:text-5xl">
                Turn your videos into{"\n"}
                <span className="bg-gradient-to-r from-fuchsia-400 via-violet-400 to-cyan-400 bg-clip-text text-transparent">
                  viral AI creations
                </span>
              </h1>
              <p className="mx-auto mt-4 max-w-sm text-sm leading-relaxed text-white/60 sm:text-base">
                {hero.description}
              </p>

              <div className="landing-entrance landing-entrance-d5 mt-7 flex items-center justify-center gap-4">
                <AvatarStack />
                <div className="min-w-0 text-left">
                  <div className="text-sm font-semibold text-white">{hero.socialProof.headline}</div>
                  <div className="text-xs text-white/40">Fast, fun, and built for vertical video.</div>
                </div>
              </div>
            </div>
          </section>

          {/* ── Popular Effects ── */}
          <section className="relative mt-16 px-4">
            {/* Ambient glow between sections */}
            <div className="pointer-events-none absolute -top-20 left-1/2 h-40 w-80 -translate-x-1/2 rounded-full bg-fuchsia-600/[0.06] blur-[80px]" aria-hidden="true" />

            <SectionHeader
              title="Popular Effects"
              subtitle="Trending transformations loved by creators"
              action={
                <Link
                  href="/effects"
                  className="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-white/10 bg-white/[0.03] px-3.5 py-1.5 text-xs font-semibold text-white/70 transition-all duration-200 hover:border-white/20 hover:bg-white/[0.06] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                >
                  View all <IconArrowRight className="h-3.5 w-3.5" />
                </Link>
              }
            />

            {effectsState.status === "error" ? (
              <div className="mt-5 rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
                <div className="text-sm font-semibold text-red-100">Couldn&apos;t load effects</div>
                <div className="mt-1 text-xs text-red-100/60">{effectsState.message}</div>
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
              <div className="mt-5 rounded-3xl border border-white/[0.07] bg-white/[0.03] p-5 text-center text-sm text-white/50">
                No effects yet.
              </div>
            ) : (
              <HorizontalCarousel className="mt-5 -mx-4" showRightFade scrollRef={effectsCarouselRef}>
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

            {effectsState.status !== "empty" && showEffectsHint ? (
              <p className="mt-3 text-center text-xs text-white/30">Swipe to explore more effects →</p>
            ) : null}
          </section>

          {/* ── Public Gallery ── */}
          <section className="relative mt-16 px-4">
            <div className="pointer-events-none absolute -top-16 right-0 h-32 w-64 rounded-full bg-violet-600/[0.05] blur-[70px]" aria-hidden="true" />

            <SectionHeader
              title="Public Gallery"
              subtitle="See what creators are making"
              action={
                <button
                  type="button"
                  onClick={() => router.push("/explore")}
                  className="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-white/10 bg-white/[0.03] px-3.5 py-1.5 text-xs font-semibold text-white/70 transition-all duration-200 hover:border-white/20 hover:bg-white/[0.06] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                >
                  View all <IconArrowRight className="h-3.5 w-3.5" />
                </button>
              }
            />

            {galleryState.status === "error" ? (
              <div className="mt-5 rounded-3xl border border-red-500/20 bg-red-500/[0.07] p-5">
                <div className="text-sm font-semibold text-red-100">Couldn&apos;t load gallery</div>
                <div className="mt-1 text-xs text-red-100/60">{galleryState.message}</div>
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
              <div className="mt-5 rounded-3xl border border-white/[0.07] bg-white/[0.03] p-5 text-center text-sm text-white/50">
                No public videos yet.
              </div>
            ) : (
              <div className="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
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

            <div className="mt-6 overflow-hidden rounded-3xl border border-white/[0.07] bg-white/[0.03] p-5 text-center">
              <p className="text-sm text-white/60">Explore the full gallery and discover new effects.</p>
              <button
                type="button"
                onClick={() => router.push("/explore")}
                className="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-white text-sm font-semibold text-black transition-all duration-200 hover:scale-[1.01] hover:bg-white/90 hover:shadow-[0_8px_24px_rgba(255,255,255,0.1)] focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                <IconGallery className="h-5 w-5" />
                Open Explore
              </button>
            </div>
          </section>

          {/* ── Features ── */}
          <section className="relative mt-16 px-4 pb-10">
            <div className="pointer-events-none absolute -top-16 left-0 h-32 w-64 rounded-full bg-cyan-600/[0.04] blur-[70px]" aria-hidden="true" />

            <h2 className="text-2xl font-bold leading-tight tracking-tight text-white sm:text-3xl lg:text-4xl">
              Create like a pro,{" "}
              <span className="bg-gradient-to-r from-fuchsia-400 to-violet-400 bg-clip-text text-transparent">
                no experience required
              </span>
            </h2>
            <p className="mt-3 max-w-md text-sm leading-relaxed text-white/55 sm:text-base">
              Our AI does the heavy lifting so you can focus on being creative.
            </p>

            <div className="mt-8 grid gap-3 sm:grid-cols-2">
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

                const colors = FEATURE_ICON_COLORS[f.id] ?? { bg: "from-fuchsia-500/25 to-violet-500/20", text: "text-fuchsia-200" };

                return <FeatureRow key={f.id} title={f.title} description={f.description} icon={icon} colors={colors} />;
              })}
            </div>

            {/* Trust section */}
            <div className="relative mt-10 overflow-hidden rounded-3xl border border-white/[0.07] bg-white/[0.03]">
              {/* Glow accent behind trust card */}
              <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(236,72,153,0.12),transparent_60%),radial-gradient(ellipse_at_80%_50%,rgba(99,102,241,0.08),transparent_55%)]" />
              <div className="relative p-5">
                <div className="text-base font-semibold text-white sm:text-lg">
                  Join over <span className="bg-gradient-to-r from-fuchsia-400 to-violet-400 bg-clip-text text-transparent">1 million creators</span> making viral videos
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  {trustBadges.map((t) => (
                    <span
                      key={t}
                      className="inline-flex items-center gap-2 rounded-full border border-white/[0.08] bg-black/30 px-3.5 py-1.5 text-xs font-medium text-white/60"
                    >
                      <span
                        className="h-1.5 w-1.5 rounded-full bg-emerald-400"
                        style={{ animation: "dot-pulse 2.5s ease-in-out infinite" }}
                        aria-hidden="true"
                      />
                      {t}
                    </span>
                  ))}
                </div>
              </div>
            </div>
          </section>
        </main>

      </div>

    </div>
  );
}
