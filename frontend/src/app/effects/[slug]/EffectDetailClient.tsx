"use client";

import Link from "next/link";
import { IconPlay, IconSparkles } from "@/app/_components/landing/icons";
import { ApiError, getEffect, type ApiEffect } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import useEffectUploadStart from "@/lib/useEffectUploadStart";
import useUiGuards from "@/components/guards/useUiGuards";
import useAuthToken from "@/lib/useAuthToken";
import { useSearchParams } from "next/navigation";
import { useEffect, useMemo, useRef, useState } from "react";
import { SlidersHorizontal } from "lucide-react";
import EffectPromptFields from "@/components/effects/EffectPromptFields";
import EffectTokenInfo from "@/components/effects/EffectTokenInfo";
import EffectUploadFooter from "@/components/effects/EffectUploadFooter";

type LoadState =
  | { status: "loading" }
  | { status: "success"; data: ApiEffect }
  | { status: "not_found" }
  | { status: "error"; message: string; code?: number };

export default function EffectDetailClient({ slug }: { slug: string }) {
  const searchParams = useSearchParams();
  const [state, setState] = useState<LoadState>({ status: "loading" });
  const [reload, setReload] = useState(0);
  const autoUploadRef = useRef(false);
  const { requireAuth, ensureTokens, openPlans, walletBalance, loadWalletBalance } = useUiGuards();
  const token = useAuthToken();
  const [positivePrompt, setPositivePrompt] = useState("");
  const [negativePrompt, setNegativePrompt] = useState("");
  const {
    fileInputRef,
    startUpload,
    onFileSelected,
    uploadState,
    clearUploadError,
  } = useEffectUploadStart({
    slug,
  });

  useEffect(() => {
    if (!token) return;
    void loadWalletBalance();
  }, [loadWalletBalance, token]);

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

  const creditsCost = useMemo(() => {
    if (state.status !== "success") return 0;
    const raw = state.data.credits_cost;
    return Math.max(0, Math.ceil(Number(raw ?? 0)));
  }, [state]);

  const hasEnoughTokens = creditsCost === 0 || (walletBalance !== null && walletBalance >= creditsCost);

  const uploadLabel = !token ? "Sign in to try" : "Try This Effect";
  const disableUpload: boolean = false;
  const isConfigurable = state.status === "success" && state.data.type === "configurable";

  useEffect(() => {
    if (autoUploadRef.current) return;
    if (searchParams.get("upload") !== "1") return;
    if (state.status !== "success") return;
    if (isConfigurable) return;
    autoUploadRef.current = true;
    clearUploadError();
    if (!requireAuth()) return;
    void (async () => {
      const okTokens = await ensureTokens(creditsCost);
      if (!okTokens) return;
      const result = startUpload(slug);
      if (!result.ok && result.reason === "unauthenticated") {
        requireAuth();
      }
    })();
  }, [clearUploadError, creditsCost, ensureTokens, isConfigurable, requireAuth, searchParams, slug, startUpload, state.status]);

  const handleStartUpload = async () => {
    clearUploadError();
    if (!requireAuth()) return;
    const okTokens = await ensureTokens(creditsCost);
    if (!okTokens) return;
    if (isConfigurable) {
      const result = startUpload(slug, { positivePrompt, negativePrompt });
      if (!result.ok && result.reason === "unauthenticated") {
        requireAuth();
      }
      return;
    }
    const result = startUpload(slug);
    if (!result.ok && result.reason === "unauthenticated") {
      requireAuth();
    }
  };

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div
        className={`mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl ${
          state.status === "success" ? "pb-[calc(6.5rem+env(safe-area-inset-bottom))]" : ""
        }`}
      >
        <header className="flex items-center gap-2 text-xs text-white/55">
          <IconSparkles className="h-4 w-4 text-fuchsia-200" />
          Effect detail
        </header>

        {state.status === "loading" && (
          <div className="mt-4 overflow-hidden rounded-3xl border border-white/10 bg-white/5">
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
          <div className="mt-4 rounded-3xl border border-red-500/25 bg-red-500/10 p-5">
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
          <div className="mt-4 rounded-3xl border border-white/10 bg-white/5 p-5 text-center">
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
          <main className="mt-4 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <section className="overflow-hidden rounded-3xl border border-white/10 bg-white/5">
              <div className="relative aspect-[9/16] w-full bg-gradient-to-br from-fuchsia-500/18 to-indigo-500/12">
                {state.data.preview_video_url ? (
                  <VideoPlayer
                    className="absolute inset-0 h-full w-full object-cover"
                    src={state.data.preview_video_url}
                    muted
                    loop
                    autoPlay
                    playsInline
                    preload="metadata"
                  />
                ) : state.data.thumbnail_url ? (
                  <img
                    className="absolute inset-0 h-full w-full object-cover"
                    src={state.data.thumbnail_url}
                    alt={state.data.name}
                  />
                ) : null}
                {!state.data.preview_video_url ? (
                  <>
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_25%_20%,rgba(255,255,255,0.18),transparent_55%),radial-gradient(circle_at_70%_70%,rgba(0,0,0,0.35),transparent_65%)]" />
                    <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/25 to-black/80" />

                    <div className="absolute inset-0 grid place-items-center">
                      <span className="grid h-16 w-16 place-items-center rounded-full border border-white/25 bg-black/35 backdrop-blur-sm shadow-lg">
                        <IconPlay className="h-7 w-7 translate-x-0.5 text-white/90" />
                      </span>
                    </div>
                  </>
                ) : null}
                {state.data.is_premium ? (
                  <span className="absolute left-3 top-3 inline-flex items-center rounded-full border border-white/20 bg-black/45 px-3 py-1 text-[11px] font-semibold text-white/90 backdrop-blur-sm">
                    Premium
                  </span>
                ) : null}
                {isConfigurable ? (
                  <span className="absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white/85 backdrop-blur-sm">
                    <SlidersHorizontal className="h-3.5 w-3.5" />
                  </span>
                ) : null}
              </div>

              <div className="p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <h1 className="text-2xl font-semibold tracking-tight text-white">{state.data.name}</h1>
                </div>

                {state.data.description ? (
                  <p className="mt-3 text-sm leading-6 text-white/70">{state.data.description}</p>
                ) : (
                  <p className="mt-3 text-sm leading-6 text-white/60">A one?click effect to transform your video.</p>
                )}
              </div>
            </section>

            <aside className="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div className="text-sm font-semibold text-white">Ready to try it?</div>
              <div className="mt-2 text-xs leading-5 text-white/60">
                {token
                  ? "You're signed in. Upload your video to start processing."
                  : "Sign in to upload your video and start processing."}
              </div>

              <EffectTokenInfo
                creditsCost={creditsCost}
                walletBalance={walletBalance}
                hasEnoughTokens={hasEnoughTokens}
                isAuthenticated={!!token}
                onTopUp={() => openPlans(creditsCost)}
              />

              {isConfigurable ? (
                <EffectPromptFields
                  positivePrompt={positivePrompt}
                  onPositivePromptChange={setPositivePrompt}
                  negativePrompt={negativePrompt}
                  onNegativePromptChange={setNegativePrompt}
                />
              ) : null}

              {uploadState.status === "error" ? (
                <div className="mt-3 rounded-2xl border border-red-500/30 bg-red-500/10 px-3 py-2 text-[11px] text-red-200">
                  {uploadState.message}
                </div>
              ) : null}

              <div className="mt-4 text-[11px] text-white/45">
                Effect slug: <span className="font-mono text-white/60">{state.data.slug}</span>
              </div>
            </aside>
          </main>
        )}
      </div>

      {state.status === "success" ? (
        <EffectUploadFooter
          label={uploadLabel}
          disabled={disableUpload}
          onClick={handleStartUpload}
        />
      ) : null}

    </div>
  );
}

