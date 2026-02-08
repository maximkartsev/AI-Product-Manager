"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ApiError, getPublicGalleryItem, type GalleryVideo } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { Textarea } from "@/components/ui/textarea";
import { Info, SlidersHorizontal } from "lucide-react";
import useEffectUploadStart from "@/lib/useEffectUploadStart";
import useUiGuards from "@/components/guards/useUiGuards";

type GalleryDetailState =
  | { status: "loading" }
  | { status: "success"; data: GalleryVideo }
  | { status: "error"; message: string };

export default function ExploreDetailClient({ id }: { id: number }) {
  const router = useRouter();
  const [state, setState] = useState<GalleryDetailState>({ status: "loading" });
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [positivePrompt, setPositivePrompt] = useState("");
  const [negativePrompt, setNegativePrompt] = useState("");
  const { requireAuth } = useUiGuards();
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

  const isConfigurable = state.status === "success" && state.data.effect?.type === "configurable";

  const onUploadClick = () => {
    clearUploadError();
    if (!requireAuth()) return;
    if (isConfigurable) {
      const result = startUpload(effectSlug, { positivePrompt, negativePrompt });
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
    setPositivePrompt("");
    setNegativePrompt("");
  }, [id]);

  useEffect(() => {
    if (state.status !== "success") return;
    if (seededPromptsRef.current) return;
    const payload = state.data.input_payload;
    const positive = typeof payload?.positive_prompt === "string" ? payload.positive_prompt.trim() : "";
    const negative = typeof payload?.negative_prompt === "string" ? payload.negative_prompt.trim() : "";
    if (positive || negative) {
      setPositivePrompt(positive);
      setNegativePrompt(negative);
    }
    seededPromptsRef.current = true;
  }, [state]);

  const data = state.status === "success" ? state.data : null;
  const effectName = data?.effect?.name ?? "AI Effect";
  const title = effectName;
  const effectDescription = useMemo(() => (data?.effect?.description ?? "").trim(), [data?.effect?.description]);
  const isPremium = Boolean(data?.effect?.is_premium);

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div className="mx-auto w-full max-w-md px-4 pb-12 pt-4 sm:max-w-xl lg:max-w-3xl">
        <div className="mt-4">
          <h1 className="text-base font-semibold text-white">Public Gallery</h1>
        </div>

        <main className="mt-6">
          {state.status === "error" ? (
            <div className="rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
              {state.message}
            </div>
          ) : null}
          {uploadError ? (
            <div className="mt-3 rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
              {uploadError}
            </div>
          ) : null}

          {state.status === "loading" ? (
            <div className="rounded-3xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
              Loading video…
            </div>
          ) : null}

          {state.status === "success" ? (
            <>
              <section className="relative aspect-[9/16] w-full overflow-hidden rounded-3xl border border-white/10 bg-white/5">
                {data?.processed_file_url ? (
                  <VideoPlayer
                    className="h-full w-full object-cover"
                    src={data.processed_file_url}
                    playsInline
                    autoPlay
                    loop
                    muted
                    preload="metadata"
                  />
                ) : data?.thumbnail_url ? (
                  <img className="h-full w-full object-cover" src={data.thumbnail_url} alt={title} />
                ) : (
                  <div className="flex h-full w-full items-center justify-center text-xs text-white/50">Video preview</div>
                )}
                <div className="pointer-events-none absolute inset-0">
                  <div className="absolute bottom-4 left-4 text-xs font-medium text-white/80">
                    dzzzs.com • {effectName}
                  </div>
                </div>
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
              </section>

              <div className="mt-5 text-center">
                <div className="text-lg font-semibold text-white">{title}</div>
                <div className="mt-1 text-xs text-white/55">{effectName}</div>
                {effectDescription ? <div className="mt-2 text-xs leading-5 text-white/60">{effectDescription}</div> : null}
              </div>

              {isConfigurable ? (
                <div className="mt-6 space-y-3 rounded-3xl border border-white/10 bg-white/5 p-4">
                  <div>
                    <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
                      <span>Positive prompt</span>
                      <button
                        type="button"
                        className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/70"
                        title="Describe what you want to see in the output."
                        aria-label="Positive prompt info"
                      >
                        <Info className="h-3 w-3" />
                      </button>
                    </div>
                    <Textarea
                      value={positivePrompt}
                      onChange={(event) => setPositivePrompt(event.target.value)}
                      placeholder="Describe the look, style, or details to add..."
                      className="mt-2 min-h-[84px] text-xs"
                    />
                  </div>
                  <div>
                    <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
                      <span>Negative prompt</span>
                      <button
                        type="button"
                        className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/70"
                        title="Describe what you want to avoid."
                        aria-label="Negative prompt info"
                      >
                        <Info className="h-3 w-3" />
                      </button>
                    </div>
                    <Textarea
                      value={negativePrompt}
                      onChange={(event) => setNegativePrompt(event.target.value)}
                      placeholder="Describe elements to avoid..."
                      className="mt-2 min-h-[84px] text-xs"
                    />
                  </div>
                </div>
              ) : null}


              <div className="mt-6">
                <button
                  type="button"
                  onClick={onUploadClick}
                  disabled={!data?.effect?.slug}
                  className="inline-flex h-12 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300 disabled:opacity-70"
                >
                  Use This Effect
                </button>
                <Link
                  href="/explore"
                  className="mt-3 inline-flex h-11 w-full items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-sm font-semibold text-white/80 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                >
                  Back to Gallery
                </Link>
              </div>
            </>
          ) : null}
        </main>
      </div>
    </div>
  );
}
