"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import AuthModal from "@/app/_components/landing/AuthModal";
import { ApiError, getPublicGalleryItem, type GalleryVideo } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import { IconSparkles } from "@/app/_components/landing/icons";
import { ChevronLeft } from "lucide-react";
import useEffectUploadStart from "@/lib/useEffectUploadStart";

type GalleryDetailState =
  | { status: "loading" }
  | { status: "success"; data: GalleryVideo }
  | { status: "error"; message: string };

export default function ExploreDetailClient({ id }: { id: number }) {
  const router = useRouter();
  const [state, setState] = useState<GalleryDetailState>({ status: "loading" });
  const [uploadError, setUploadError] = useState<string | null>(null);
  const effectSlug = state.status === "success" ? state.data.effect?.slug ?? null : null;
  const {
    fileInputRef,
    startUpload,
    onFileSelected,
    authOpen,
    closeAuth,
    clearUploadError,
  } = useEffectUploadStart({
    slug: effectSlug,
    onError: setUploadError,
  });

  const onUploadClick = () => {
    clearUploadError();
    startUpload(effectSlug);
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

  const data = state.status === "success" ? state.data : null;
  const title = useMemo(() => data?.title?.trim() || "Untitled", [data?.title]);
  const effectName = data?.effect?.name ?? "AI Effect";
  const effectDescription = useMemo(() => (data?.effect?.description ?? "").trim(), [data?.effect?.description]);

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
        <header className="flex items-center justify-between">
          <Link href="/" className="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-white">
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-white/10">
              <IconSparkles className="h-4 w-4 text-fuchsia-200" />
            </span>
            <span className="uppercase">DZZZS</span>
          </Link>
          <span className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-white/70">
            Creator
          </span>
        </header>

        <div className="mt-6 flex items-center justify-between">
          <button
            type="button"
            onClick={() => router.back()}
            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
            aria-label="Back"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <h1 className="text-base font-semibold text-white">Public Gallery</h1>
          <div className="h-9 w-9" aria-hidden />
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
              </section>

              <div className="mt-5 text-center">
                <div className="text-lg font-semibold text-white">{title}</div>
                <div className="mt-1 text-xs text-white/55">{effectName}</div>
                {effectDescription ? (
                  <div className="mt-2 text-xs leading-5 text-white/60">{effectDescription}</div>
                ) : null}
              </div>

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
      <AuthModal open={authOpen} onClose={closeAuth} initialMode="signin" />
    </div>
  );
}
