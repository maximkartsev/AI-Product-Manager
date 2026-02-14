"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import {
  setAccessToken,
  setTenantDomain,
  clearTenantDomain,
} from "@/lib/api";

function SignInDoneInner() {
  const searchParams = useSearchParams();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const errorParam = searchParams.get("error");
    const errorCode = searchParams.get("error_code");
    const accessToken = searchParams.get("access_token");

    if (errorParam) {
      if (errorCode === "404") {
        window.location.href = "/?auth=signup";
        return;
      }
      setError(errorParam);
      return;
    }

    if (!accessToken) {
      setError("Apple sign-in failed. No authentication data received.");
      return;
    }

    setAccessToken(accessToken);
    const tenantDomain = searchParams.get("tenant_domain");
    if (tenantDomain) {
      setTenantDomain(tenantDomain);
    } else {
      clearTenantDomain();
    }

    window.location.href = "/";
  }, [searchParams]);

  if (error) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-zinc-950 p-4">
        <div className="w-full max-w-md rounded-2xl border border-red-500/30 bg-red-500/10 p-6 text-center">
          <h2 className="text-lg font-semibold text-red-200">Sign-in failed</h2>
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
      <p className="text-sm text-white/60">Signing you in...</p>
    </div>
  );
}

export default function AppleSignInDonePage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center bg-zinc-950">
          <p className="text-sm text-white/60">Loading...</p>
        </div>
      }
    >
      <SignInDoneInner />
    </Suspense>
  );
}
