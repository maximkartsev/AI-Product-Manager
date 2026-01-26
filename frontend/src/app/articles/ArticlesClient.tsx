"use client";

import { apiGet } from "@/lib/api/client";
import { clearAccessToken, getAccessToken, setAccessToken } from "@/lib/api/auth";
import { ApiError } from "@/lib/api/errors";
import { useRouter, useSearchParams } from "next/navigation";
import { type FormEvent, useEffect, useMemo, useState } from "react";

type Article = {
  id: number;
  title: string;
  sub_title?: string | null;
  state: string;
  content?: string | null;
  published_at?: string | null;
};

type ArticleIndexData = {
  items: Article[];
  totalItems: number;
  totalPages: number;
  page: number;
  perPage: number;
  order: string;
  search: string | null;
};

type LoadState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "success"; data: ArticleIndexData }
  | { status: "empty" }
  | { status: "error"; message: string; code?: number };

export default function ArticlesClient() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const q = searchParams.get("q") ?? "";
  const [queryInput, setQueryInput] = useState(q);

  const [token, setToken] = useState<string | null | undefined>(undefined);
  const [tokenInput, setTokenInput] = useState("");
  const [state, setState] = useState<LoadState>({ status: "idle" });

  useEffect(() => {
    setToken(getAccessToken());
  }, []);

  useEffect(() => {
    setQueryInput(q);
  }, [q]);

  const query = useMemo(() => {
    return q ? { search: q } : undefined;
  }, [q]);

  useEffect(() => {
    if (token === undefined) return;

    let cancelled = false;

    async function run() {
      setState({ status: "loading" });

      try {
        const data = await apiGet<ArticleIndexData>("/articles", query, token);

        if (cancelled) return;

        if (!data?.items || data.items.length === 0) {
          setState({ status: "empty" });
          return;
        }

        setState({ status: "success", data });
      } catch (e) {
        if (cancelled) return;

        if (e instanceof ApiError) {
          setState({ status: "error", message: e.message, code: e.status });
          return;
        }

        setState({ status: "error", message: "Unexpected error while loading articles." });
      }
    }

    void run();

    return () => {
      cancelled = true;
    };
  }, [query, token]);

  function onSubmitSearch(e: FormEvent) {
    e.preventDefault();
    const next = queryInput.trim();
    const url = next ? `/articles?q=${encodeURIComponent(next)}` : "/articles";
    router.push(url);
  }

  function onSaveToken() {
    const next = tokenInput.trim();
    if (!next) return;
    setAccessToken(next);
    setToken(next);
    setTokenInput("");
  }

  function onClearToken() {
    clearAccessToken();
    setToken(null);
  }

  return (
    <div className="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-black dark:text-zinc-50">
      <main className="mx-auto w-full max-w-3xl px-6 py-12">
        <header className="flex flex-col gap-3">
          <h1 className="text-3xl font-semibold tracking-tight">Articles</h1>
          <p className="text-sm text-zinc-600 dark:text-zinc-400">
            This page intentionally exercises the Laravel API contract in a browser context (CORS + auth + envelope +
            UI states).
          </p>
        </header>

        <section className="mt-8 grid gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <div className="text-sm font-medium">Auth token</div>
              <div className="text-xs text-zinc-600 dark:text-zinc-400">
                Status:{" "}
                {token ? (
                  <span className="font-medium text-emerald-700 dark:text-emerald-400">present</span>
                ) : token === undefined ? (
                  <span className="font-medium text-zinc-600 dark:text-zinc-400">checking</span>
                ) : (
                  <span className="font-medium text-amber-700 dark:text-amber-400">missing</span>
                )}
              </div>
            </div>

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <input
                className="h-10 w-full rounded-md border border-zinc-200 bg-white px-3 text-sm outline-none focus:border-zinc-400 dark:border-zinc-800 dark:bg-black dark:focus:border-zinc-600 sm:w-80"
                placeholder="Paste Bearer token (from /api/login or /api/register)"
                value={tokenInput}
                onChange={(e) => setTokenInput(e.target.value)}
              />
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={onSaveToken}
                  className="h-10 rounded-md bg-zinc-900 px-3 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-50 dark:text-black dark:hover:bg-zinc-200"
                >
                  Save
                </button>
                <button
                  type="button"
                  onClick={onClearToken}
                  className="h-10 rounded-md border border-zinc-200 bg-white px-3 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-800 dark:bg-black dark:hover:bg-zinc-900"
                >
                  Clear
                </button>
              </div>
            </div>
          </div>

          <form onSubmit={onSubmitSearch} className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <input
              className="h-10 w-full rounded-md border border-zinc-200 bg-white px-3 text-sm outline-none focus:border-zinc-400 dark:border-zinc-800 dark:bg-black dark:focus:border-zinc-600"
              placeholder="Search (maps to ?search=… in the API)"
              value={queryInput}
              onChange={(e) => setQueryInput(e.target.value)}
            />
            <button
              type="submit"
              className="h-10 rounded-md border border-zinc-200 bg-white px-3 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-800 dark:bg-black dark:hover:bg-zinc-900"
            >
              Search
            </button>
          </form>
        </section>

        <section className="mt-8">
          {state.status === "loading" && (
            <div className="rounded-xl border border-zinc-200 bg-white p-6 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400">
              Loading…
            </div>
          )}

          {state.status === "error" && (
            <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-sm text-red-900 dark:border-red-900/40 dark:bg-red-950/40 dark:text-red-200">
              <div className="font-medium">Error</div>
              <div className="mt-1">
                {state.code ? <span className="font-medium">HTTP {state.code}</span> : null}
                {state.code ? ": " : null}
                {state.message}
              </div>
              {state.code === 401 ? (
                <div className="mt-2 text-red-800/80 dark:text-red-200/80">
                  Tip: authenticate via `/api/login` or `/api/register`, then paste the token above.
                </div>
              ) : null}
            </div>
          )}

          {state.status === "empty" && (
            <div className="rounded-xl border border-zinc-200 bg-white p-6 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400">
              No articles yet.
            </div>
          )}

          {state.status === "success" && (
            <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
              <div className="border-b border-zinc-200 px-6 py-4 text-sm text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
                {state.data.totalItems} total • page {state.data.page} / {state.data.totalPages}
              </div>
              <ul className="divide-y divide-zinc-200 dark:divide-zinc-800">
                {state.data.items.map((a) => (
                  <li key={a.id} className="px-6 py-4">
                    <div className="flex items-center justify-between gap-4">
                      <div className="min-w-0">
                        <div className="truncate font-medium">{a.title}</div>
                        <div className="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                          id: {a.id} • state: {a.state}
                          {a.published_at ? ` • published_at: ${a.published_at}` : ""}
                        </div>
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      </main>
    </div>
  );
}

