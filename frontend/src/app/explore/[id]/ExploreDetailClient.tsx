"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { ApiError, getPublicGalleryItem, type GalleryVideo } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { SlidersHorizontal } from "lucide-react";
import useEffectUploadStart from "@/lib/useEffectUploadStart";
import useUiGuards from "@/components/guards/useUiGuards";
import useAuthToken from "@/lib/useAuthToken";
import { IconSparkles } from "@/app/_components/landing/icons";
import EffectConfigFields from "@/components/effects/EffectConfigFields";
import type { PendingAssetsMap } from "@/lib/effectUploadTypes";
import EffectTokenInfo from "@/components/effects/EffectTokenInfo";
import EffectUploadFooter from "@/components/effects/EffectUploadFooter";

type GalleryDetailState =
  | { status: "loading" }
  | { status: "success"; data: GalleryVideo }
  | { status: "error"; message: string };

export default function ExploreDetailClient({ id }: { id: number }) {
  const [state, setState] = useState<GalleryDetailState>({ status: "loading" });
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [inputPayload, setInputPayload] = useState<Record<string, unknown>>({});
  const [pendingAssets, setPendingAssets] = useState<PendingAssetsMap>({});
  const { requireAuth, ensureTokens, openPlans, walletBalance, loadWalletBalance } = useUiGuards();
  const token = useAuthToken();
  const seededPromptsRef = useRef(false);
  const effectSlug = state.status === "success" ? state.data.effect?.slug ?? null : null;
  const {
    fileInputRef,
    startUpload,
    onFileSelected,
    clearUploadError,
  } = useEffectUploadStart({
    slug: effectSlug,
    onError: setUploadError,
  });

  const configurableProps = useMemo(
    () => (state.status === "success" ? state.data.effect?.configurable_properties ?? [] : []),
    [state],
  );
  const isConfigurable =
    state.status === "success" && state.data.effect?.type === "configurable" && configurableProps.length > 0;

  const creditsCost = useMemo(() => {
    if (state.status !== "success") return 0;
    const raw = state.data.effect?.credits_cost;
    return Math.max(0, Math.ceil(Number(raw ?? 0)));
  }, [state]);

  const hasEnoughTokens = creditsCost === 0 || (walletBalance !== null && walletBalance >= creditsCost);

  const uploadLabel = !token ? "Sign in to try" : "Try This Effect";

  useEffect(() => {
    if (!token) return;
    void loadWalletBalance();
  }, [loadWalletBalance, token]);

  const onUploadClick = async () => {
    clearUploadError();
    if (!requireAuth()) return;
    const okTokens = await ensureTokens(creditsCost);
    if (!okTokens) return;
    if (isConfigurable) {
      const hasPayload = Object.keys(inputPayload).length > 0;
      const result = startUpload(effectSlug, hasPayload ? inputPayload : null, pendingAssets);
      if (!result.ok && result.reason === "unauthenticated") {
        requireAuth();
      }
      return;
    }
    const result = startUpload(effectSlug);
    if (!result.ok && result.reason === "unauthenticated") {
      requireAuth();
    }
  };

  useEffect(() => {
    let cancelled = false;
    setState({ status: "loading" });

    void (async () => {
      try {
        const data = await getPublicGalleryItem(id);
        if (cancelled) return;
        setState({ status: "success", data });
      } catch (err) {
        if (cancelled) return;
        const message = err instanceof ApiError ? err.message : "Could not load the gallery video.";
        setState({ status: "error", message });
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [id]);

  useEffect(() => {
    seededPromptsRef.current = false;
    setInputPayload({});
    setPendingAssets({});
  }, [id]);

  useEffect(() => {
    if (state.status !== "success") return;
    if (seededPromptsRef.current) return;
    const payload = state.data.input_payload;
    const allowed = new Set(configurableProps.map((prop) => prop.key));
    const nextPayload: Record<string, unknown> = {};
    if (payload && typeof payload === "object") {
      Object.entries(payload).forEach(([key, val]) => {
        if (!allowed.has(key)) return;
        if (val === null || val === undefined) return;
        nextPayload[key] = val;
      });
    }
    if (Object.keys(nextPayload).length > 0) {
      setInputPayload(nextPayload);
    }
    seededPromptsRef.current = true;
  }, [configurableProps, state]);

  const data = state.status === "success" ? state.data : null;
  const effectName = data?.effect?.name ?? "AI Effect";
  const effectDescription = useMemo(() => (data?.effect?.description ?? "").trim(), [data?.effect?.description]);
  const isPremium = Boolean(data?.effect?.is_premium);

  return (
    <div className="noise-overlay min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div
        className={`relative mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl ${
          state.status === "success" ? "pb-[calc(6.5rem+env(safe-area-inset-bottom))]" : ""
        }`}
      >
        {/* Ambient background glows */}
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
          <div
            className="absolute -left-24 top-20 h-56 w-56 rounded-full bg-fuchsia-600/10 blur-[90px]"
            style={{ animation: "glow-drift 16s ease-in-out infinite" }}
          />
          <div
            className="absolute -right-16 top-64 h-44 w-44 rounded-full bg-violet-600/[0.08] blur-[80px]"
            style={{ animation: "glow-drift-reverse 18s ease-in-out infinite" }}
          />
        </div>

        <header className="effects-entrance relative flex items-center gap-2 text-xs text-white/55">
          <IconSparkles className="h-4 w-4 text-fuchsia-200" />
          <Link href="/explore" className="hover:text-white/80 transition">
            Gallery
          </Link>
          <span className="text-white/30">/</span>
          <span>{effectName}</span>
        </header>

        {state.status === "loading" && (
          <div className="effects-entrance effects-entrance-d1 mt-4 overflow-hidden rounded-3xl border border-white/[0.07] bg-white/[0.03]">
            <div className="relative aspect-[9/16] w-full animate-pulse bg-gradient-to-br from-fuchsia-500/15 to-indigo-500/10" />
            <div className="p-5">
              <div className="h-6 w-48 animate-pulse rounded bg-white/10" />
              <div className="mt-3 h-4 w-full animate-pulse rounded bg-white/5" />
              <div className="mt-2 h-4 w-3/4 animate-pulse rounded bg-white/5" />
            </div>
          </div>
        )}

        {state.status === "error" && (
          <div className="mt-4 rounded-3xl border border-red-500/25 bg-red-500/10 p-5">
            <div className="text-sm font-semibold text-red-100">Error</div>
            <div className="mt-1 text-xs text-red-100/75">{state.message}</div>
          </div>
        )}

        {state.status === "success" && (
          <main className="effects-entrance effects-entrance-d1 mt-4 grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
            <section className="min-w-0 overflow-hidden rounded-3xl border border-white/[0.07] bg-white/[0.03]">
              <div className="relative aspect-[9/16] w-full bg-gradient-to-br from-fuchsia-500/18 to-indigo-500/12">
                {data?.processed_file_url ? (
                  <VideoPlayer
                    className="absolute inset-0 h-full w-full object-cover"
                    src={data.processed_file_url}
                    playsInline
                    autoPlay
                    loop
                    muted
                    preload="metadata"
                  />
                ) : data?.thumbnail_url ? (
                  <img
                    className="absolute inset-0 h-full w-full object-cover"
                    src={data.thumbnail_url}
                    alt={effectName}
                  />
                ) : null}
                {isPremium ? (
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
                <h1 className="text-2xl font-semibold tracking-tight text-white">{effectName}</h1>
                {effectDescription ? (
                  <p className="mt-3 text-sm leading-6 text-white/70">{effectDescription}</p>
                ) : (
                  <p className="mt-3 text-sm leading-6 text-white/60">A one-click effect to transform your video.</p>
                )}
              </div>
            </section>

            <aside className="min-w-0 rounded-3xl border border-white/[0.07] bg-white/[0.03] p-5">
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
                <EffectConfigFields
                  properties={configurableProps}
                  value={inputPayload}
                  onChange={setInputPayload}
                  pendingAssets={pendingAssets}
                  onPendingAssetsChange={setPendingAssets}
                />
              ) : null}

              {uploadError ? (
                <div className="mt-3 rounded-2xl border border-red-500/30 bg-red-500/10 px-3 py-2 text-[11px] text-red-200">
                  {uploadError}
                </div>
              ) : null}
            </aside>
          </main>
        )}
      </div>

      {state.status === "success" ? (
        <EffectUploadFooter
          label={uploadLabel}
          disabled={!effectSlug}
          onClick={onUploadClick}
        />
      ) : null}
    </div>
  );
}
