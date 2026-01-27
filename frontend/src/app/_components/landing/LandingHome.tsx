"use client";

import { useMemo, useState, type ReactNode } from "react";
import AuthModal from "./AuthModal";
import { brand, features, hero, popularEffects, publicGallery, trustBadges, type Effect, type GalleryItem } from "./landingData";
import { IconArrowRight, IconBolt, IconGallery, IconPlay, IconSparkles, IconWand } from "./icons";

function gradientClass(from: string, to: string) {
  return `${from} ${to}`;
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
            <div className="shrink-0 rounded-full border border-white/15 bg-black/35 px-2.5 py-1 text-[11px] font-medium text-white/80">
              {effect.stats.uses}
            </div>
          </div>
        </div>

        <div className="p-3">
          <div className="flex items-center justify-between gap-3">
            <div className="text-xs text-white/60">
              {effect.badge ? <span className="font-medium text-white/80">{effect.badge}</span> : "Trending now"}
            </div>
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
  return (
    <button
      type="button"
      onClick={onUse}
      className="group overflow-hidden rounded-3xl border border-white/10 bg-white/5 text-left shadow-[0_10px_30px_rgba(0,0,0,0.25)] transition hover:border-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
      aria-label={`Open public gallery item: ${item.title}`}
    >
      <div className={`relative aspect-[9/13] bg-gradient-to-br ${g}`}>
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,0.28),transparent_45%),radial-gradient(circle_at_70%_70%,rgba(0,0,0,0.35),transparent_65%)]" />
        <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/20 to-black/75" />

        <div className="absolute inset-0 grid place-items-center">
          <span className="grid h-14 w-14 place-items-center rounded-full border border-white/25 bg-black/30 backdrop-blur-sm transition group-hover:scale-[1.02]">
            <IconPlay className="h-6 w-6 translate-x-0.5 text-white/90" />
          </span>
        </div>
      </div>

      <div className="p-3">
        <div className="truncate text-sm font-semibold text-white">{item.title}</div>
        <div className="mt-1 flex items-center justify-between gap-2 text-[11px] text-white/60">
          <span className="truncate">{item.effect}</span>
          <span className="inline-flex shrink-0 items-center gap-1 text-white/65">
            <span aria-hidden="true">♥</span>
            <span>{item.stats.likes}</span>
          </span>
        </div>
        <div className="mt-0.5 text-[11px] text-white/45">{item.stats.views}</div>
      </div>
    </button>
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
  const [authOpen, setAuthOpen] = useState(false);

  const openAuth = () => setAuthOpen(true);
  const closeAuth = () => setAuthOpen(false);

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="relative mx-auto w-full max-w-md sm:max-w-xl lg:max-w-4xl">
        <header className="absolute inset-x-0 top-0 z-20 px-4 pt-4">
          <div className="flex items-center justify-between">
            <a
              href="/"
              className="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-white"
              aria-label={`${brand.name} home`}
            >
              <span className="grid h-8 w-8 place-items-center rounded-xl bg-white/10">
                <IconSparkles className="h-4 w-4 text-fuchsia-200" />
              </span>
              <span className="uppercase">{brand.name}</span>
            </a>

            <PillButton onClick={openAuth} ariaLabel="Sign in">
              Sign In
            </PillButton>
          </div>
        </header>

        <main className="pb-32">
          <section className="relative">
            <div className="relative w-full overflow-hidden bg-zinc-900/50 md:mx-auto md:mt-6 md:max-w-md md:rounded-3xl">
              <div className="relative aspect-[9/16] w-full">
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

                <button
                  type="button"
                  aria-label="Play hero preview"
                  className="absolute inset-0 grid place-items-center"
                  onClick={openAuth}
                >
                  <span className="grid h-20 w-20 place-items-center rounded-full border border-white/25 bg-black/40 backdrop-blur-sm shadow-2xl">
                    <IconPlay className="h-9 w-9 translate-x-0.5 text-white/90" />
                  </span>
                </button>

                <div className="absolute bottom-4 left-4 w-[calc(100%-2rem)] max-w-[340px]">
                  <div className="flex items-start gap-3 rounded-2xl border border-white/10 bg-black/45 px-3 py-2 backdrop-blur-sm">
                    <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white/10">
                      <IconSparkles className="h-4 w-4 text-fuchsia-200" />
                    </span>
                    <div className="min-w-0">
                      <div className="truncate text-sm font-semibold text-white">{hero.effectLabel}</div>
                      <div className="mt-0.5 line-clamp-2 text-xs leading-5 text-white/70">{hero.effectDescription}</div>
                    </div>
                  </div>
                </div>
              </div>
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

            <div className="mt-4 -mx-4 overflow-x-auto px-4 pb-2 no-scrollbar snap-x snap-mandatory scroll-px-4">
              <div className="flex gap-3">
                {popularEffects.map((effect) => (
                  <EffectCard key={effect.id} effect={effect} onTry={openAuth} />
                ))}
              </div>
            </div>

            <p className="mt-3 text-center text-xs text-white/40">Swipe to explore more effects →</p>
          </section>

          <section className="mt-12 px-4">
            <div className="flex items-center justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold tracking-tight text-white">Public Gallery</h2>
                <p className="mt-1 text-xs text-white/50">See what creators are making</p>
              </div>
              <button
                type="button"
                onClick={openAuth}
                className="inline-flex items-center gap-1 text-xs font-semibold text-white/70 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                View all <IconArrowRight className="h-4 w-4" />
              </button>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {publicGallery.map((item) => (
                <GalleryCard key={item.id} item={item} onUse={openAuth} />
              ))}
            </div>

            <div className="mt-6 rounded-3xl border border-white/10 bg-white/5 p-4 text-center">
              <p className="text-sm text-white/70">Sign in to explore the full gallery and access your creations</p>
              <button
                type="button"
                onClick={openAuth}
                className="mt-3 inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                <IconGallery className="h-5 w-5" />
                Sign in to explore
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

        <div className="fixed inset-x-0 bottom-0 z-40">
          <div className="mx-auto w-full max-w-md px-4 pb-[calc(16px+env(safe-area-inset-bottom))] sm:max-w-xl lg:max-w-4xl">
            <div className="rounded-3xl border border-white/10 bg-black/70 p-2 backdrop-blur-md supports-[backdrop-filter]:bg-black/40">
              <button
                type="button"
                onClick={openAuth}
                className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300"
              >
                <IconWand className="h-5 w-5" />
                Do the Same
              </button>
            </div>
          </div>
        </div>
      </div>

      <AuthModal open={authOpen} onClose={closeAuth} />
    </div>
  );
}

