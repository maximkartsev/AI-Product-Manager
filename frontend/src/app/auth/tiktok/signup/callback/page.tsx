"use client";

import { Suspense, useEffect } from "react";
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

  useEffect(() => {
    const code = searchParams.get("code");
    const state = searchParams.get("state");

    if (!code) {
      window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong. Please try again or use another sign-in method.");
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
          window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong on our end. Please try again in a few minutes.");
        } else {
          window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong. Please try again or use another sign-in method.");
        }
      });
  }, [searchParams]);

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
