import { Suspense } from "react";
import ArticlesClient from "./ArticlesClient";

export default function ArticlesPage() {
  return (
    <Suspense
      fallback={
        <div className="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-black dark:text-zinc-50">
          <main className="mx-auto w-full max-w-3xl px-6 py-12">
            <div className="rounded-xl border border-zinc-200 bg-white p-6 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400">
              Loading…
            </div>
          </main>
        </div>
      }
    >
      <ArticlesClient />
    </Suspense>
  );
}

