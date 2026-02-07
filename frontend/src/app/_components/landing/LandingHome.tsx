"use client";

import {
  ApiError,
  getAccessToken,
  getEffects,
  getMe,
  getPublicGallery,
  type ApiEffect,
  type GalleryVideo,
} from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import useEffectUploadStart from "@/lib/useEffectUploadStart";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import AuthModal from "./AuthModal";
import { brand, features, hero, trustBadges, type Effect, type GalleryItem } from "./landingData";
import { IconArrowRight, IconBolt, IconGallery, IconPlay, IconSparkles, IconWand } from "./icons";
import { SlidersHorizontal } from "lucide-react";

function gradientClass(from: string, to: string) {
  return `${from} ${to}`;
}

type LandingEffect = Effect & { slug: string; preview_video_url?: string | null };

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

const EFFECT_GRADIENTS = [
  { from: "from-fuchsia-500", to: "to-cyan-400" },
  { from: "from-amber-400", to: "to-pink-500" },
  { from: "from-sky-400", to: "to-indigo-500" },
  { from: "from-lime-400", to: "to-emerald-500" },
  { from: "from-cyan-400", to: "to-blue-500" },
  { from: "from-fuchsia-500", to: "to-violet-500" },
] as const;

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
  };
}

function pickFeaturedEffect(effects: LandingEffect[]): LandingEffect | null {
  if (!effects.length) return null;
  // TODO: Replace with usage-frequency ranking once available.
  const idx = Math.floor(Math.random() * effects.length);
  return effects[idx] ?? null;
}

function EffectCardSkeleton({ gradient }: { gradient: { from: string; to: string } }) {
  const g = gradientClass(gradient.from, gradient.to);

  return (
    <div className="snap-start animate-pulse">
      <div className="w-44 overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.35)]">
        <div className={`relative aspect-[9/12] bg-gradient-to-br ${g}`}>
          <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />
          <div className="absolute bottom-3 left-3 right-3">
            <div className="h-3 w-24 rounded bg-white/15" />
            <div className="mt-2 h-3 w-32 rounded bg-white/10" />
          </div>
        </div>
        <div className="p-3">
          <div className="flex items-center justify-between gap-3">
            <div className="h-3 w-20 rounded bg-white/10" />
            <div className="h-7 w-16 rounded-full bg-white/15" />
          </div>
        </div>
      </div>
    </div>
  );
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

function PillButton({
  children,
  onClick,
  variant = "ghost",
  ariaLabel,
}: {
  children: ReactNode;
  onClick?: () => void;
  variant?: "ghost" | "solid";
  ariaLabel?: string;
}) {
  const base =
    "inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400";
  const styles =
    variant === "solid"
      ? "bg-white text-black hover:bg-white/90"
      : "border border-white/10 bg-white/5 text-white/80 hover:bg-white/10";
  return (
    <button type="button" aria-label={ariaLabel} className={`${base} ${styles}`} onClick={onClick}>
      {children}
    </button>
  );
}

function EffectCard({ effect, onTry }: { effect: Effect; onTry: () => void }) {
  const g = gradientClass(effect.gradient.from, effect.gradient.to);

  return (
    <div className="snap-start">
      <div className="w-44 overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.35)]">
        <div className={`relative aspect-[9/12] bg-gradient-to-br ${g}`}>
          {effect.thumbnail_url ? (
            <img
              className="absolute inset-0 h-full w-full object-cover"
              src={effect.thumbnail_url}
              alt={effect.name}
            />
          ) : null}
          {effect.is_premium ? (
            <span className="absolute left-3 top-3 inline-flex items-center rounded-full border border-white/20 bg-black/45 px-2.5 py-1 text-[10px] font-semibold text-white/90 backdrop-blur-sm">
              Premium
            </span>
          ) : null}
          {effect.type === "configurable" ? (
            <span className="absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white/85 backdrop-blur-sm">
              <SlidersHorizontal className="h-3.5 w-3.5" />
            </span>
          ) : null}
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.35),transparent_40%),radial-gradient(circle_at_70%_70%,rgba(0,0,0,0.35),transparent_60%)]" />
          <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />

          <button
            type="button"
            aria-label={`Preview ${effect.name}`}
            className="absolute inset-0 grid place-items-center text-white/90"
            onClick={onTry}
          >
            <span className="grid h-14 w-14 place-items-center rounded-full border border-white/25 bg-black/35 backdrop-blur-sm shadow-lg">
              <IconPlay className="h-6 w-6 translate-x-0.5" />
            </span>
          </button>

          <div className="absolute bottom-3 left-3 right-3 flex items-center justify-between gap-3">
            <div className="min-w-0">
              <div className="truncate text-sm font-semibold text-white">{effect.name}</div>
              <div className="truncate text-xs text-white/75">{effect.tagline}</div>
            </div>
            {!effect.is_premium ? (
              <div className="shrink-0 rounded-full border border-white/15 bg-black/35 px-2.5 py-1 text-[11px] font-medium text-white/80">
                {effect.stats.uses}
              </div>
            ) : null}
          </div>
        </div>

        <div className="p-3">
          <div className="flex items-center justify-between gap-3">
            <button
              type="button"
              onClick={onTry}
              className="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Try This
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function GalleryCard({ item, onUse }: { item: GalleryItem; onUse: () => void }) {
  const g = gradientClass(item.gradient.from, item.gradient.to);
  const showPlayOverlay = !item.processed_file_url || Boolean(item.thumbnail_url);
  return (
    <button
      type="button"
      onClick={onUse}
      className="group overflow-hidden rounded-3xl border border-white/10 bg-white/5 text-left shadow-[0_10px_30px_rgba(0,0,0,0.25)] transition hover:border-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
      aria-label={`Open public gallery item: ${item.title}`}
    >
      <div className={`relative aspect-[9/13] bg-gradient-to-br ${g}`}>
        {item.thumbnail_url ? (
          <img className="absolute inset-0 h-full w-full object-cover" src={item.thumbnail_url} alt={item.title} />
        ) : item.processed_file_url ? (
          <VideoPlayer
            className="absolute inset-0 h-full w-full object-cover"
            src={item.processed_file_url}
            playsInline
            autoPlay
            loop
            muted
            preload="metadata"
          />
        ) : null}
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.28),transparent_45%),radial-gradient(circle_at_70%_70%,rgba(0,0,0,0.35),transparent_65%)]" />
        <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/75" />

        {showPlayOverlay ? (
          <div className="absolute inset-0 grid place-items-center">
            <span className="grid h-14 w-14 place-items-center rounded-full border border-white/25 bg-black/30 backdrop-blur-sm transition group-hover:scale-[1.02]">
              <IconPlay className="h-6 w-6 translate-x-0.5 text-white/90" />
            </span>
          </div>
        ) : null}
      </div>

      <div className="p-3">
        <div className="truncate text-sm font-semibold text-white">{item.title}</div>
        <div className="mt-1 flex items-center justify-between gap-2 text-[11px] text-white/60">
          <span className="truncate">{item.effect}</span>
          {item.stats?.likes ? (
            <span className="inline-flex shrink-0 items-center gap-1 text-white/65">
              <span aria-hidden="true">♥</span>
              <span>{item.stats.likes}</span>
            </span>
          ) : null}
        </div>
        {item.stats?.views ? <div className="mt-0.5 text-[11px] text-white/45">{item.stats.views}</div> : null}
      </div>
    </button>
  );
}

function GalleryCardSkeleton({ gradient }: { gradient: { from: string; to: string } }) {
  const g = gradientClass(gradient.from, gradient.to);
  return (
    <div className="animate-pulse overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-[0_10px_30px_rgba(0,0,0,0.25)]">
      <div className={`relative aspect-[9/13] bg-gradient-to-br ${g}`}>
        <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/70" />
        <div className="absolute bottom-3 left-3 right-3">
          <div className="h-3 w-24 rounded bg-white/15" />
          <div className="mt-2 h-3 w-16 rounded bg-white/10" />
        </div>
      </div>
      <div className="p-3">
        <div className="h-3 w-20 rounded bg-white/10" />
        <div className="mt-2 h-3 w-24 rounded bg-white/5" />
      </div>
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
  const [authOpen, setAuthOpen] = useState(false);
  const [effectsState, setEffectsState] = useState<EffectsState>({ status: "loading" });
  const [effectsReload, setEffectsReload] = useState(0);
  const [featuredEffect, setFeaturedEffect] = useState<LandingEffect | null>(null);
  const [pendingDoSameSlug, setPendingDoSameSlug] = useState<string | null>(null);
  const [galleryState, setGalleryState] = useState<PublicGalleryState>({ status: "loading" });
  const [galleryReload, setGalleryReload] = useState(0);
  const [token, setToken] = useState<string | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const {
    fileInputRef,
    startUpload,
    onFileSelected,
    authOpen: uploadAuthOpen,
    closeAuth: closeUploadAuth,
    clearUploadError,
  } = useEffectUploadStart({
    slug: null,
  });
  const combinedAuthOpen = authOpen || uploadAuthOpen;

  const openAuth = () => setAuthOpen(true);
  const closeAuth = () => {
    closeUploadAuth();
    setAuthOpen(false);
    const nextToken = getAccessToken();
    setToken(nextToken);
    if (pendingDoSameSlug) {
      if (nextToken) {
        goToEffect(pendingDoSameSlug);
      }
      setPendingDoSameSlug(null);
    }
  };

  const goToEffect = (slug: string) => {
    router.push(`/effects/${encodeURIComponent(slug)}`);
  };

  const handleEffectTry = (effect: LandingEffect) => {
    if (effect.type === "configurable") {
      goToEffect(effect.slug);
      return;
    }
    clearUploadError();
    startUpload(effect.slug);
  };

  const handleDoSameClick = () => {
    if (!featuredEffect) return;
    const activeToken = token ?? getAccessToken();
    if (!activeToken) {
      setPendingDoSameSlug(featuredEffect.slug);
      setAuthOpen(true);
      return;
    }
    if (!token) setToken(activeToken);
    if (featuredEffect.type === "configurable") {
      goToEffect(featuredEffect.slug);
      return;
    }
    clearUploadError();
    startUpload(featuredEffect.slug);
  };

  useEffect(() => {
    let cancelled = false;

    async function run() {
      setEffectsState({ status: "loading" });

      try {
        const data = await getEffects();
        if (cancelled) return;

        const items = (data ?? []).filter((e) => e && e.is_active).map(toLandingEffect);
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

  useEffect(() => {
    const t = window.setTimeout(() => setToken(getAccessToken()), 0);
    return () => window.clearTimeout(t);
  }, []);

  useEffect(() => {
    if (!token) {
      setIsAdmin(false);
      return;
    }

    let cancelled = false;
    getMe()
      .then((data) => {
        if (cancelled) return;
        setIsAdmin(Boolean(data.is_admin));
      })
      .catch(() => {
        if (cancelled) return;
        setIsAdmin(false);
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

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
        <header className="absolute inset-x-0 top-0 z-20 px-4 pt-4">
          <div className="flex items-center justify-between">
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

            <div className="flex items-center gap-2">
              {isAdmin ? (
                <PillButton onClick={() => router.push("/admin/effects")} ariaLabel="Admin">
                  Admin
                </PillButton>
              ) : null}
              <PillButton onClick={openAuth} ariaLabel={token ? "Account" : "Sign in"}>
                {token ? "Account" : "Sign In"}
              </PillButton>
            </div>
          </div>
        </header>

        <main className="pb-32">
          <section className="relative">
            <div className="relative w-full overflow-hidden bg-zinc-900/50 md:mx-auto md:mt-6 md:max-w-md md:rounded-3xl">
              <div className="relative aspect-[9/16] w-full">
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

                <div className="absolute bottom-4 left-4 w-[calc(100%-2rem)] max-w-[340px]">
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
              <div className="mt-4 -mx-4 overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4">
                <div className="flex gap-3">
                  {effectsState.status === "success"
                    ? effectsState.data.map((effect) => (
                        <EffectCard key={effect.slug} effect={effect} onTry={() => handleEffectTry(effect)} />
                      ))
                    : EFFECT_GRADIENTS.slice(0, 4).map((g, idx) => <EffectCardSkeleton key={idx} gradient={g} />)}
                </div>
              </div>
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
                      <GalleryCard key={item.id} item={item} onUse={() => router.push(`/explore/${item.id}`)} />
                    ))
                  : EFFECT_GRADIENTS.slice(0, 4).map((g, idx) => <GalleryCardSkeleton key={idx} gradient={g} />)}
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

      <AuthModal open={combinedAuthOpen} onClose={closeAuth} />
    </div>
  );
}

