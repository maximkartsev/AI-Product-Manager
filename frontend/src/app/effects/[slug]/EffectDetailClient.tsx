"use client";

import AuthModal from "@/app/_components/landing/AuthModal";
import { IconPlay, IconSparkles, IconWand } from "@/app/_components/landing/icons";
import { ApiError, getAccessToken, getEffect, type ApiEffect } from "@/lib/api";
import Link from "next/link";
import { useEffect, useState } from "react";

type LoadState =
  | { status: "loading" }
  | { status: "success"; data: ApiEffect }
  | { status: "not_found" }
  | { status: "error"; message: string; code?: number };

export default function EffectDetailClient({ slug }: { slug: string }) {
  const [state, setState] = useState<LoadState>({ status: "loading" });
  const [reload, setReload] = useState(0);

  const [authOpen, setAuthOpen] = useState(false);
  const [token, setToken] = useState<string | null>(null);

  useEffect(() => {
    const t = window.setTimeout(() => setToken(getAccessToken()), 0);
    return () => window.clearTimeout(t);
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      setState({ status: "loading" });

      try {
        const data = await getEffect(slug);
        if (cancelled) return;

        setState({ status: "success", data });
      } catch (err) {
        if (cancelled) return;

        if (err instanceof ApiError) {
          if (err.status === 404) {
            setState({ status: "not_found" });
            return;
          }

          setState({ status: "error", message: err.message, code: err.status });
          return;
        }

        setState({ status: "error", message: "Unexpected error while loading the effect." });
      }
    }

    void run();

    return () => {
      cancelled = true;
    };
  }, [reload, slug]);

  function openAuth() {
    setAuthOpen(true);
  }

  function closeAuth() {
    setAuthOpen(false);
    setToken(getAccessToken());
  }

  function onUploadClick() {
    if (!token) {
      openAuth();
      return;
    }

    // Upload flow is implemented in a later iteration.
    window.alert("Upload flow is coming next. You’re authenticated and ready to continue.");
  }

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl">
        <header className="flex items-center justify-between gap-4">
          <Link
            href="/"
            className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
          >
            <span aria-hidden="true">←</span> Back
          </Link>

          <div className="inline-flex items-center gap-2 text-xs text-white/55">
            <IconSparkles className="h-4 w-4 text-fuchsia-200" />
            Effect detail
          </div>
        </header>

        {state.status === "loading" && (
          <div className="mt-6 overflow-hidden rounded-3xl border border-white/10 bg-white/5">
            <div className="relative aspect-[9/16] w-full animate-pulse bg-gradient-to-br from-fuchsia-500/15 to-indigo-500/10" />
            <div className="p-5">
              <div className="h-6 w-48 animate-pulse rounded bg-white/10" />
              <div className="mt-3 h-4 w-full animate-pulse rounded bg-white/5" />
              <div className="mt-2 h-4 w-3/4 animate-pulse rounded bg-white/5" />
              <div className="mt-5 h-12 w-full animate-pulse rounded-2xl bg-white/10" />
            </div>
          </div>
        )}

        {state.status === "error" && (
          <div className="mt-6 rounded-3xl border border-red-500/25 bg-red-500/10 p-5">
            <div className="text-sm font-semibold text-red-100">Error</div>
            <div className="mt-1 text-xs text-red-100/75">
              {state.code ? <span className="font-semibold">HTTP {state.code}</span> : null}
              {state.code ? ": " : null}
              {state.message}
            </div>
            <button
              type="button"
              onClick={() => setReload((v) => v + 1)}
              className="mt-4 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Retry
            </button>
          </div>
        )}

        {state.status === "not_found" && (
          <div className="mt-6 rounded-3xl border border-white/10 bg-white/5 p-5 text-center">
            <div className="text-sm font-semibold text-white">Effect not found</div>
            <div className="mt-1 text-xs text-white/60">This effect may have been removed or is unavailable.</div>
            <Link
              href="/"
              className="mt-4 inline-flex h-11 w-full items-center justify-center rounded-2xl bg-white text-sm font-semibold text-black transition hover:bg-white/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            >
              Back to home
            </Link>
          </div>
        )}

        {state.status === "success" && (
          <main className="mt-6 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <section className="overflow-hidden rounded-3xl border border-white/10 bg-white/5">
              <div className="relative aspect-[9/16] w-full bg-gradient-to-br from-fuchsia-500/18 to-indigo-500/12">
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_25%_20%,rgba(255,255,255,0.18),transparent_55%),radial-gradient(circle_at_70%_70%,rgba(0,0,0,0.35),transparent_65%)]" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/25 to-black/80" />

                <div className="absolute inset-0 grid place-items-center">
                  <span className="grid h-16 w-16 place-items-center rounded-full border border-white/25 bg-black/35 backdrop-blur-sm shadow-lg">
                    <IconPlay className="h-7 w-7 translate-x-0.5 text-white/90" />
                  </span>
                </div>
              </div>

              <div className="p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <h1 className="text-2xl font-semibold tracking-tight text-white">{state.data.name}</h1>
                  {state.data.is_premium ? (
                    <span className="inline-flex items-center rounded-full border border-white/15 bg-black/35 px-3 py-1 text-xs font-semibold text-white/80">
                      Premium
                    </span>
                  ) : (
                    <span className="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-white/70">
                      Free
                    </span>
                  )}
                </div>

                {state.data.description ? (
                  <p className="mt-3 text-sm leading-6 text-white/70">{state.data.description}</p>
                ) : (
                  <p className="mt-3 text-sm leading-6 text-white/60">A one‑click effect to transform your video.</p>
                )}
              </div>
            </section>

            <aside className="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div className="text-sm font-semibold text-white">Ready to try it?</div>
              <div className="mt-2 text-xs leading-5 text-white/60">
                {token
                  ? "You’re signed in. Upload flow is the next step in the product journey."
                  : "You’ll be prompted to sign in before uploading."}
              </div>

              <button
                type="button"
                onClick={onUploadClick}
                className="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300"
              >
                <IconWand className="h-5 w-5" />
                Upload Video
              </button>

              <div className="mt-4 text-[11px] text-white/45">
                Effect slug: <span className="font-mono text-white/60">{state.data.slug}</span>
              </div>
            </aside>
          </main>
        )}
      </div>

      <AuthModal open={authOpen} onClose={closeAuth} />
    </div>
  );
}

