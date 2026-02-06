"use client";

import AuthModal from "@/app/_components/landing/AuthModal";
import { IconPlay, IconSparkles, IconWand } from "@/app/_components/landing/icons";
import {
  ApiError,
  createVideo,
  getAccessToken,
  getEffect,
  getWallet,
  initVideoUpload,
  submitAiJob,
  type ApiEffect,
} from "@/lib/api";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState, type ChangeEvent } from "react";

type LoadState =
  | { status: "loading" }
  | { status: "success"; data: ApiEffect }
  | { status: "not_found" }
  | { status: "error"; message: string; code?: number };

type WalletState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "ready"; balance: number }
  | { status: "error"; message: string };

type UploadState =
  | { status: "idle" }
  | { status: "uploading" }
  | { status: "queued"; jobId: number }
  | { status: "error"; message: string };

type Plan = {
  id: string;
  name: string;
  price: string;
  tokens: string;
  description: string;
};

const PLANS: Plan[] = [
  {
    id: "starter",
    name: "Starter",
    price: "$9",
    tokens: "50 tokens",
    description: "Great for trying a few effects and sharing.",
  },
  {
    id: "creator",
    name: "Creator",
    price: "$19",
    tokens: "150 tokens",
    description: "Best value for active creators and weekly uploads.",
  },
  {
    id: "studio",
    name: "Studio",
    price: "$39",
    tokens: "400 tokens",
    description: "For teams and high-volume production pipelines.",
  },
];

function formatUploadError(err: ApiError): string {
  const base = err.message || "Upload failed.";
  const payload = err.data as { data?: unknown } | undefined;
  if (!payload || typeof payload !== "object" || !("data" in payload)) return base;

  const data = payload.data as Record<string, unknown> | undefined;
  if (!data) return base;

  const requiredTokens = data.required_tokens;
  if (typeof requiredTokens === "number") {
    return `${base} (required tokens: ${requiredTokens})`;
  }

  return base;
}

function getRequiredTokens(err: ApiError): number | null {
  const payload = err.data as { data?: unknown } | undefined;
  if (!payload || typeof payload !== "object" || !("data" in payload)) return null;
  const data = payload.data as Record<string, unknown> | undefined;
  if (!data) return null;
  const requiredTokens = data.required_tokens;
  return typeof requiredTokens === "number" ? requiredTokens : null;
}

function normalizeUploadHeaders(
  headers: Record<string, string | string[]> | undefined,
  fallbackContentType: string,
): Record<string, string> {
  const normalized: Record<string, string> = {};
  if (headers) {
    for (const [key, value] of Object.entries(headers)) {
      if (Array.isArray(value)) {
        if (value[0]) normalized[key] = value[0];
        continue;
      }
      if (value) normalized[key] = value;
    }
  }

  if (!normalized["Content-Type"]) {
    normalized["Content-Type"] = fallbackContentType;
  }

  return normalized;
}

function PlansModal({
  open,
  onClose,
  requiredTokens,
  balance,
}: {
  open: boolean;
  onClose: () => void;
  requiredTokens: number;
  balance: number | null;
}) {
  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="plans-title"
    >
      <button
        type="button"
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={onClose}
        aria-label="Close plans dialog"
      />
      <div className="relative w-full max-w-md">
        <div className="relative rounded-3xl border border-white/10 bg-zinc-950/90 p-5 shadow-2xl">
          <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(236,72,153,0.18),transparent_55%),radial-gradient(circle_at_70%_30%,rgba(99,102,241,0.14),transparent_60%)]" />
          <div className="relative">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h2 id="plans-title" className="text-xl font-semibold text-white">
                  Not enough tokens
                </h2>
                <p className="mt-1 text-xs text-white/60">
                  This effect costs {requiredTokens} tokens.{" "}
                  {balance !== null ? `Your balance is ${balance} tokens.` : "Sign in to view your balance."}
                </p>
              </div>
              <button
                type="button"
                onClick={onClose}
                className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/5 text-white/70 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                aria-label="Close"
              >
                X
              </button>
            </div>

            <div className="mt-4 grid gap-3">
              {PLANS.map((plan) => (
                <div key={plan.id} className="rounded-2xl border border-white/10 bg-white/5 p-4">
                  <div className="flex items-center justify-between">
                    <div className="text-sm font-semibold text-white">{plan.name}</div>
                    <div className="text-sm font-semibold text-fuchsia-200">{plan.price}</div>
                  </div>
                  <div className="mt-1 text-xs text-white/60">{plan.tokens}</div>
                  <div className="mt-2 text-xs text-white/50">{plan.description}</div>
                  <button
                    type="button"
                    className="mt-3 inline-flex h-9 w-full items-center justify-center rounded-xl border border-white/10 bg-white/5 text-xs font-semibold text-white/60"
                    disabled
                  >
                    Coming soon
                  </button>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function EffectDetailClient({ slug }: { slug: string }) {
  const [state, setState] = useState<LoadState>({ status: "loading" });
  const [reload, setReload] = useState(0);

  const [authOpen, setAuthOpen] = useState(false);
  const [plansOpen, setPlansOpen] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [walletState, setWalletState] = useState<WalletState>({ status: "idle" });
  const [uploadState, setUploadState] = useState<UploadState>({ status: "idle" });
  const [pendingUpload, setPendingUpload] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    const t = window.setTimeout(() => setToken(getAccessToken()), 0);
    return () => window.clearTimeout(t);
  }, []);

  useEffect(() => {
    if (!token) {
      setWalletState({ status: "idle" });
      return;
    }

    let cancelled = false;
    setWalletState({ status: "loading" });

    getWallet()
      .then((data) => {
        if (cancelled) return;
        setWalletState({ status: "ready", balance: data.balance });
      })
      .catch((err) => {
        if (cancelled) return;
        const message = err instanceof ApiError ? err.message : "Unable to load token balance.";
        setWalletState({ status: "error", message });
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

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

  const hasEnoughTokens =
    creditsCost === 0 || (walletState.status === "ready" && walletState.balance >= creditsCost);
  const requiresWallet = !!token && creditsCost > 0;
  const isUploadLocked = requiresWallet && (!hasEnoughTokens || walletState.status !== "ready");
  const isUploading = uploadState.status === "uploading";

  function openAuth() {
    setAuthOpen(true);
  }

  function openPlans() {
    setPlansOpen(true);
  }

  function closePlans() {
    setPlansOpen(false);
  }

  function closeAuth() {
    setAuthOpen(false);
    const nextToken = getAccessToken();
    setToken(nextToken);
    if (pendingUpload && nextToken) {
      window.setTimeout(() => fileInputRef.current?.click(), 0);
    }
    setPendingUpload(false);
  }

  function onUploadClick() {
    if (!token) {
      setPendingUpload(true);
      openAuth();
      return;
    }

    if (walletState.status === "ready" && !hasEnoughTokens && creditsCost > 0) {
      openPlans();
      return;
    }

    if (isUploadLocked || isUploading) {
      return;
    }

    fileInputRef.current?.click();
  }

  async function onFileSelected(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

    if (!token) {
      setUploadState({ status: "error", message: "Sign in to upload a video." });
      return;
    }

    if (state.status !== "success") {
      setUploadState({ status: "error", message: "Effect data is not ready yet." });
      return;
    }

    if (isUploadLocked) {
      setUploadState({ status: "error", message: "Insufficient tokens to upload." });
      if (walletState.status === "ready" && !hasEnoughTokens && creditsCost > 0) {
        openPlans();
      }
      return;
    }

    const mimeType = file.type || "video/mp4";

    setUploadState({ status: "uploading" });

    try {
      const init = await initVideoUpload({
        effect_id: state.data.id,
        mime_type: mimeType,
        size: file.size,
        original_filename: file.name,
      });

      const uploadHeaders = normalizeUploadHeaders(init.upload_headers, mimeType);
      const uploadResponse = await fetch(init.upload_url, {
        method: "PUT",
        headers: uploadHeaders,
        body: file,
      });

      if (!uploadResponse.ok) {
        throw new Error(`Upload failed with ${uploadResponse.status}`);
      }

      const video = await createVideo({
        effect_id: state.data.id,
        original_file_id: init.file.id,
        title: file.name,
      });

      const idempotencyKey = `effect_${state.data.id}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
      const job = await submitAiJob({
        effect_id: state.data.id,
        video_id: video.id,
        idempotency_key: idempotencyKey,
      });

      setUploadState({ status: "queued", jobId: job.id });

      try {
        const wallet = await getWallet();
        setWalletState({ status: "ready", balance: wallet.balance });
      } catch {
        // Ignore wallet refresh errors after upload.
      }
    } catch (err) {
      if (err instanceof ApiError) {
        const requiredTokens = getRequiredTokens(err);
        if (requiredTokens !== null) {
          openPlans();
        }
        setUploadState({ status: "error", message: formatUploadError(err) });
        return;
      }
      setUploadState({ status: "error", message: "Unexpected error while uploading the video." });
    }
  }

  const uploadLabel = !token
    ? "Sign in to try"
    : isUploading
      ? "Uploading..."
      : uploadState.status === "queued"
        ? "Queued for processing"
        : "Try This Effect";
  const disableUpload: boolean =
    !!token &&
    (isUploading ||
      uploadState.status === "queued" ||
      (requiresWallet && walletState.status !== "ready"));

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div className="mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl">
        <header className="flex items-center justify-between gap-4">
          <Link
            href="/"
            className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
          >
            <span aria-hidden="true">?</span> Back
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
                {state.data.preview_video_url ? (
                  <video
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
                  ) : null}
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

              <div className="mt-3 rounded-2xl border border-white/10 bg-black/25 px-3 py-2 text-[11px] text-white/65">
                <div>Cost: {creditsCost} tokens</div>
                {token ? (
                  walletState.status === "loading" ? (
                    <div>Checking balance...</div>
                  ) : walletState.status === "ready" ? (
                    <div>Balance: {walletState.balance} tokens</div>
                  ) : walletState.status === "error" ? (
                    <div>{walletState.message}</div>
                  ) : (
                    <div>Balance: --</div>
                  )
                ) : (
                  <div>Sign in to view your balance.</div>
                )}
                {token && walletState.status === "ready" && !hasEnoughTokens && creditsCost > 0 ? (
                  <div className="text-amber-200">Not enough tokens to upload.</div>
                ) : null}
              </div>

              {uploadState.status === "uploading" ? (
                <div className="mt-3 text-[11px] text-white/60">Uploading and queueing your job...</div>
              ) : uploadState.status === "queued" ? (
                <div className="mt-3 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-[11px] text-emerald-200">
                  Video queued. Processing will start shortly.
                </div>
              ) : uploadState.status === "error" ? (
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
        <div className="fixed inset-x-0 bottom-0 z-40">
          <div className="mx-auto w-full max-w-md px-4 pb-[calc(16px+env(safe-area-inset-bottom))] sm:max-w-xl lg:max-w-4xl">
            <div className="rounded-3xl border border-white/10 bg-black/70 p-2 backdrop-blur-md supports-[backdrop-filter]:bg-black/40">
              <button
                type="button"
                onClick={onUploadClick}
                disabled={disableUpload}
                className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300 disabled:pointer-events-none disabled:opacity-70"
              >
                <IconWand className="h-5 w-5" />
                {uploadLabel}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      <PlansModal
        open={plansOpen}
        onClose={closePlans}
        requiredTokens={creditsCost}
        balance={walletState.status === "ready" ? walletState.balance : null}
      />
      <AuthModal open={authOpen} onClose={closeAuth} />
    </div>
  );
}

