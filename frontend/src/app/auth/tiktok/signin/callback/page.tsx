"use client";

import { Suspense, useEffect } from "react";
import { useSearchParams } from "next/navigation";
import {
  handleTikTokSignInCallback,
  setAccessToken,
  setTenantDomain,
  clearTenantDomain,
  ApiError,
} from "@/lib/api";

function SignInCallbackInner() {
  const searchParams = useSearchParams();

  useEffect(() => {
    const code = searchParams.get("code");
    const state = searchParams.get("state");

    if (!code) {
      window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong. Please try again or use another sign-in method.");
      return;
    }

    handleTikTokSignInCallback(code, state ?? undefined)
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
        if (err instanceof ApiError && err.status === 404) {
          window.location.href = "/?auth=signup";
          return;
        }
        if (err instanceof ApiError && err.status >= 500) {
          window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong on our end. Please try again in a few minutes.");
        } else {
          window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong. Please try again or use another sign-in method.");
        }
      });
  }, [searchParams]);

  return (
    <div className="flex min-h-screen items-center justify-center bg-zinc-950">
      <p className="text-sm text-white/60">Signing you in...</p>
    </div>
  );
}

export default function TikTokSignInCallbackPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center bg-zinc-950">
          <p className="text-sm text-white/60">Loading...</p>
        </div>
      }
    >
      <SignInCallbackInner />
    </Suspense>
  );
}
