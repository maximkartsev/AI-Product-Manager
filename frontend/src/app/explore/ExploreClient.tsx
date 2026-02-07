"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import AuthModal from "@/app/_components/landing/AuthModal";
import { ApiError, getPublicGallery, type GalleryIndexData, type GalleryVideo } from "@/lib/api";
import VideoPlayer from "@/components/video/VideoPlayer";
import useEffectUploadStart from "@/lib/useEffectUploadStart";
import { cn } from "@/lib/utils";
import { IconSparkles } from "@/app/_components/landing/icons";
import { ChevronLeft, Play, Search, SlidersHorizontal } from "lucide-react";

const TAG_FILTERS = [
  { id: "all", label: "All", tag: null },
  { id: "style", label: "Style", tag: "style" },
  { id: "weather", label: "Weather", tag: "weather" },
  { id: "background", label: "Background", tag: "background" },
];

const SORT_OPTIONS = [
  { id: "trending", label: "Trending" },
  { id: "latest", label: "Latest" },
  { id: "liked", label: "Most Liked" },
];

type GalleryState =
  | { status: "idle" }
  | { status: "loading"; items: GalleryVideo[]; page: number; totalPages: number }
  | { status: "success"; items: GalleryVideo[]; page: number; totalPages: number }
  | { status: "error"; message: string };

function GalleryCard({
  item,
  onOpen,
  onTry,
}: {
  item: GalleryVideo;
  onOpen: () => void;
  onTry: () => void;
}) {
  const title = item.title?.trim() || "Untitled";
  const effectName = item.effect?.name ?? "AI Effect";
  const showPlayOverlay = !item.processed_file_url || Boolean(item.thumbnail_url);
  const isConfigurable = item.effect?.type === "configurable";

  return (
    <div
      role="button"
      tabIndex={0}
      onClick={onOpen}
      onKeyDown={(event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          onOpen();
        }
      }}
      className="group relative overflow-hidden rounded-3xl border border-white/10 bg-white/5 text-left shadow-[0_10px_30px_rgba(0,0,0,0.25)] transition hover:border-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
      aria-label={`Open public gallery item: ${title}`}
    >
      <div className="relative aspect-[9/13] w-full">
        {item.thumbnail_url ? (
          <img className="absolute inset-0 h-full w-full object-cover" src={item.thumbnail_url} alt={title} />
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
        ) : (
          <div className="absolute inset-0 bg-gradient-to-br from-fuchsia-500/40 via-violet-500/30 to-cyan-400/30" />
        )}
        <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-black/25 to-black/80" />

        <button
          type="button"
          onClick={(event) => {
            event.stopPropagation();
            onTry();
          }}
          className="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full border border-white/15 bg-black/45 px-2.5 py-1 text-[11px] font-semibold text-white/90 backdrop-blur-sm transition hover:bg-black/60"
        >
          <span className="grid h-4 w-4 place-items-center rounded-full bg-white/15 text-fuchsia-100">
            <IconSparkles className="h-3 w-3" />
          </span>
          Try This
        </button>
        {isConfigurable ? (
          <span className="absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-full border border-white/20 bg-black/45 text-white/85 backdrop-blur-sm">
            <SlidersHorizontal className="h-3.5 w-3.5" />
          </span>
        ) : null}

        {showPlayOverlay ? (
          <div className="absolute inset-0 grid place-items-center">
            <span className="grid h-12 w-12 place-items-center rounded-full border border-white/25 bg-black/30 backdrop-blur-sm transition group-hover:scale-[1.02]">
              <Play className="h-5 w-5 translate-x-0.5 text-white/90" />
            </span>
          </div>
        ) : null}

        <div className="absolute bottom-3 left-3 right-3">
          <div className="text-[11px] text-white/60">{effectName}</div>
          <div className="mt-1 text-sm font-semibold text-white">{title}</div>
        </div>
      </div>
    </div>
  );
}

export default function ExploreClient() {
  const router = useRouter();
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [activeTag, setActiveTag] = useState("all");
  const [sortOpen, setSortOpen] = useState(false);
  const [sortId, setSortId] = useState("trending");
  const [page, setPage] = useState(1);
  const [galleryState, setGalleryState] = useState<GalleryState>({ status: "idle" });
  const [uploadError, setUploadError] = useState<string | null>(null);
  const {
    fileInputRef,
    startUpload,
    onFileSelected,
    authOpen,
    closeAuth,
    clearUploadError,
  } = useEffectUploadStart({
    slug: null,
    onError: setUploadError,
  });

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(search.trim());
    }, 250);
    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, activeTag]);

  useEffect(() => {
    let cancelled = false;

    setGalleryState((prev) => {
      const items = prev.status === "success" || prev.status === "loading" ? prev.items : [];
      const totalPages = prev.status === "success" || prev.status === "loading" ? prev.totalPages : 1;
      return { status: "loading", items, page, totalPages };
    });

    void (async () => {
      try {
        const filters =
          activeTag === "all"
            ? undefined
            : [
                {
                  field: "tags",
                  operator: "like",
                  value: activeTag,
                },
              ];

        const order = sortId === "latest" ? "created_at:desc" : undefined;

        const data: GalleryIndexData = await getPublicGallery({
          page,
          perPage: 12,
          search: debouncedSearch || undefined,
          order,
          filters,
        });

        if (cancelled) return;

        setGalleryState((prev) => {
          const existing = prev.status === "loading" && page > 1 ? prev.items : [];
          const merged = page > 1 ? [...existing, ...data.items] : data.items;
          return { status: "success", items: merged, page: data.page, totalPages: data.totalPages };
        });
      } catch (err) {
        if (cancelled) return;
        const message = err instanceof ApiError ? err.message : "Could not load gallery.";
        setGalleryState({ status: "error", message });
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [activeTag, debouncedSearch, page]);

  const items = galleryState.status === "success" || galleryState.status === "loading" ? galleryState.items : [];
  const canLoadMore =
    (galleryState.status === "success" || galleryState.status === "loading") &&
    galleryState.page < galleryState.totalPages;

  const activeSort = useMemo(() => SORT_OPTIONS.find((opt) => opt.id === sortId) ?? SORT_OPTIONS[0]!, [sortId]);

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <input
        ref={fileInputRef}
        type="file"
        accept="video/*"
        className="hidden"
        onChange={onFileSelected}
      />
      <div className="mx-auto w-full max-w-md px-4 pb-12 pt-4 sm:max-w-xl lg:max-w-4xl">
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
          <div className="relative">
            <button
              type="button"
              onClick={() => setSortOpen((v) => !v)}
              className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/80 transition hover:bg-white/10"
              aria-label="Filters"
            >
              <SlidersHorizontal className="h-4 w-4" />
            </button>
            {sortOpen ? (
              <div className="absolute right-0 top-11 z-20 w-40 rounded-2xl border border-white/10 bg-[#111018] p-2 shadow-[0_12px_40px_rgba(0,0,0,0.35)]">
                {SORT_OPTIONS.map((option) => (
                  <button
                    key={option.id}
                    type="button"
                    onClick={() => {
                      setSortId(option.id);
                      setSortOpen(false);
                    }}
                    className={cn(
                      "flex w-full items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold transition",
                      option.id === activeSort.id
                        ? "bg-white/10 text-fuchsia-200"
                        : "text-white/70 hover:bg-white/5",
                    )}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </div>

        <div className="mt-4 flex items-center gap-2 rounded-2xl border border-white/10 bg-white/5 px-3 py-2">
          <Search className="h-4 w-4 text-white/50" />
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search videos, effects, or tags..."
            className="w-full bg-transparent text-xs text-white placeholder:text-white/40 focus:outline-none"
          />
        </div>

        <div className="mt-4 flex gap-2 overflow-x-auto pb-1 no-scrollbar">
          {TAG_FILTERS.map((filter) => (
            <button
              key={filter.id}
              type="button"
              onClick={() => setActiveTag(filter.id)}
              className={cn(
                "inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-1.5 text-xs font-semibold transition",
                activeTag === filter.id
                  ? "bg-fuchsia-500 text-white"
                  : "border border-white/10 bg-white/5 text-white/70 hover:bg-white/10",
              )}
            >
              {filter.label}
            </button>
          ))}
        </div>

        <main className="mt-5">
          {galleryState.status === "error" ? (
            <div className="rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
              {galleryState.message}
            </div>
          ) : null}
          {uploadError ? (
            <div className="mt-3 rounded-2xl border border-red-500/20 bg-red-500/10 p-4 text-xs text-red-100">
              {uploadError}
            </div>
          ) : null}

          {items.length === 0 && galleryState.status === "success" ? (
            <div className="rounded-2xl border border-white/10 bg-white/5 p-6 text-center text-sm text-white/60">
              No public videos yet.
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-4">
              {items.map((item) => (
                <GalleryCard
                  key={item.id}
                  item={item}
                  onOpen={() => router.push(`/explore/${item.id}`)}
                  onTry={() => {
                    clearUploadError();
                    if (item.effect?.type === "configurable") {
                      router.push(`/explore/${item.id}`);
                      return;
                    }
                    if (item.effect?.slug) {
                      startUpload(item.effect.slug);
                      return;
                    }
                    router.push(`/explore/${item.id}`);
                  }}
                />
              ))}
            </div>
          )}

          {canLoadMore ? (
            <button
              type="button"
              onClick={() => setPage((p) => p + 1)}
              className="mt-6 inline-flex h-11 w-full items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-xs font-semibold text-white/70 transition hover:bg-white/10"
            >
              Load more
            </button>
          ) : null}

          {galleryState.status === "loading" && items.length === 0 ? (
            <div className="mt-6 text-center text-xs text-white/50">Loading gallery…</div>
          ) : null}
        </main>
      </div>
      <AuthModal open={authOpen} onClose={closeAuth} initialMode="signin" />
    </div>
  );
}
