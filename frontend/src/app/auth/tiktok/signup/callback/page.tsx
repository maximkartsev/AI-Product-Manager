"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import {
  handleTikTokSignUpCallback,
  setAccessToken,
  setTenantDomain,
  clearTenantDomain,
  ApiError,
} from "@/lib/api";

function SignUpCallbackInner() {
  const searchParams = useSearchParams();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const code = searchParams.get("code");
    const state = searchParams.get("state");

    if (!code) {
      setError("Missing authorization code from TikTok.");
      return;
    }

    handleTikTokSignUpCallback(code, state ?? undefined)
      .then((data) => {
        setAccessToken(data.access_token);
        if (data.tenant?.domain) {
          setTenantDomain(data.tenant.domain);
        } else {
          clearTenantDomain();
        }
        window.location.href = "/";
      })
      .catch((err) => {
        if (err instanceof ApiError && err.status >= 500) {
          setError("Something went wrong, we're already working on it. Please try again in a few minutes.");
        } else {
          setError(err instanceof Error ? err.message : "TikTok sign-up failed.");
        }
      });
  }, [searchParams]);

  if (error) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-zinc-950 p-4">
        <div className="w-full max-w-md rounded-2xl border border-red-500/30 bg-red-500/10 p-6 text-center">
          <h2 className="text-lg font-semibold text-red-200">Sign-up failed</h2>
          <p className="mt-2 text-sm text-red-300">{error}</p>
          <a
            href="/"
            className="mt-4 inline-block text-sm font-semibold text-fuchsia-300 hover:text-fuchsia-200"
          >
            Back to home
          </a>
        </div>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-zinc-950">
      <p className="text-sm text-white/60">Creating your account...</p>
    </div>
  );
}

export default function TikTokSignUpCallbackPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center bg-zinc-950">
          <p className="text-sm text-white/60">Loading...</p>
        </div>
      }
    >
      <SignUpCallbackInner />
    </Suspense>
  );
}
