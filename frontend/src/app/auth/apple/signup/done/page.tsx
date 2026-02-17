"use client";

import { Suspense, useEffect } from "react";
import { useSearchParams } from "next/navigation";
import {
  setAccessToken,
  setTenantDomain,
  clearTenantDomain,
} from "@/lib/api";

function SignUpDoneInner() {
  const searchParams = useSearchParams();

  useEffect(() => {
    const errorParam = searchParams.get("error");
    const accessToken = searchParams.get("access_token");

    if (errorParam) {
      window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong. Please try again or use another sign-in method.");
      return;
    }

    if (!accessToken) {
      window.location.href = "/?auth_error=" + encodeURIComponent("Something went wrong. Please try again or use another sign-in method.");
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

  return (
    <div className="flex min-h-screen items-center justify-center bg-zinc-950">
      <p className="text-sm text-white/60">Creating your account...</p>
    </div>
  );
}

export default function AppleSignUpDonePage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center bg-zinc-950">
          <p className="text-sm text-white/60">Loading...</p>
        </div>
      }
    >
      <SignUpDoneInner />
    </Suspense>
  );
}
