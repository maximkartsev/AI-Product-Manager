"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import AuthModal from "@/app/_components/landing/AuthModal";
import { IconSparkles } from "@/app/_components/landing/icons";
import { brand } from "@/app/_components/landing/landingData";
import { ApiError, clearAccessToken, clearTenantDomain, getMe } from "@/lib/api";
import useAuthToken from "@/lib/useAuthToken";

export default function AppHeader() {
  const router = useRouter();
  const pathname = usePathname();
  const token = useAuthToken();
  const [authOpen, setAuthOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [isAdmin, setIsAdmin] = useState(false);
  const menuRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!token) {
      setIsAdmin(false);
      return;
    }

    let cancelled = false;

    getMe()
      .then((data) => {
        if (cancelled) return;
        setIsAdmin(Boolean(data.is_admin));
      })
      .catch((err) => {
        if (cancelled) return;
        setIsAdmin(false);
        if (err instanceof ApiError && (err.status === 401 || err.status === 403)) {
          clearAccessToken();
          clearTenantDomain();
        }
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  useEffect(() => {
    if (!menuOpen) return;

    const handleClick = (event: MouseEvent) => {
      if (!menuRef.current) return;
      if (menuRef.current.contains(event.target as Node)) return;
      setMenuOpen(false);
    };

    const handleKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setMenuOpen(false);
    };

    document.addEventListener("mousedown", handleClick);
    window.addEventListener("keydown", handleKey);

    return () => {
      document.removeEventListener("mousedown", handleClick);
      window.removeEventListener("keydown", handleKey);
    };
  }, [menuOpen]);

  useEffect(() => {
    if (!token) {
      setMenuOpen(false);
    }
  }, [token]);

  const handleLogout = () => {
    clearAccessToken();
    clearTenantDomain();
    setMenuOpen(false);
    router.push("/");
  };

  const isLanding = pathname === "/";
  const wrapperClass = isLanding
    ? "absolute inset-x-0 top-0 z-30 bg-transparent text-white"
    : "w-full border-b border-white/10 bg-[#05050a] text-white";
  const buttonClass = isLanding
    ? "inline-flex items-center rounded-full border border-white/25 bg-white/15 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm transition hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
    : "inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400";
  const logoBadgeClass = isLanding
    ? "bg-gradient-to-br from-fuchsia-500 to-violet-500 text-white shadow-[0_8px_24px_rgba(0,0,0,0.35)]"
    : "bg-white/10";

  const showBack = Boolean(pathname && pathname !== "/");
  const handleBack = () => {
    if (typeof window !== "undefined" && window.history.length > 1) {
      router.back();
      return;
    }
    router.push("/");
  };

  return (
    <div className={wrapperClass}>
      <div className="mx-auto w-full max-w-md px-4 py-4 sm:max-w-xl lg:max-w-4xl">
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-4">
            {showBack ? (
              <button
                type="button"
                onClick={handleBack}
                className={buttonClass}
                aria-label="Go back"
              >
                <span aria-hidden="true">←</span>
                <span className="hidden sm:inline">Back</span>
              </button>
            ) : null}
            <Link
              href="/"
              className="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-white"
              aria-label={`${brand.name} home`}
            >
              <span className={`grid h-8 w-8 place-items-center rounded-xl ${logoBadgeClass}`}>
                <IconSparkles className="h-4 w-4 text-fuchsia-200" />
              </span>
              <span className="uppercase">{brand.name}</span>
            </Link>
          </div>

          <div className="flex items-center gap-2">
            {token && isAdmin ? (
              <button
                type="button"
                onClick={() => router.push("/admin")}
                className={buttonClass}
              >
                Administration
              </button>
            ) : null}

            {token ? (
              <div ref={menuRef} className="relative">
                <button
                  type="button"
                  aria-haspopup="menu"
                  aria-expanded={menuOpen}
                  onClick={() => setMenuOpen((v) => !v)}
                  className={buttonClass}
                >
                  Menu
                </button>
                {menuOpen ? (
                  <div
                    role="menu"
                    className="absolute right-0 top-11 z-30 w-48 rounded-2xl border border-white/10 bg-[#111018] p-2 shadow-[0_12px_40px_rgba(0,0,0,0.35)]"
                  >
                    <button
                      type="button"
                      role="menuitem"
                      onClick={() => {
                        setMenuOpen(false);
                        router.push("/user-videos");
                      }}
                      className="flex w-full items-center rounded-xl px-3 py-2 text-left text-xs font-semibold text-white/80 transition hover:bg-white/5"
                    >
                      My Videos
                    </button>
                    <button
                      type="button"
                      role="menuitem"
                      onClick={() => {
                        setMenuOpen(false);
                        router.push("/explore");
                      }}
                      className="flex w-full items-center rounded-xl px-3 py-2 text-left text-xs font-semibold text-white/80 transition hover:bg-white/5"
                    >
                      Public gallery
                    </button>
                    <div className="my-1 h-px bg-white/10" />
                    <button
                      type="button"
                      role="menuitem"
                      onClick={handleLogout}
                      className="flex w-full items-center rounded-xl px-3 py-2 text-left text-xs font-semibold text-white/80 transition hover:bg-white/5"
                    >
                      Log out
                    </button>
                  </div>
                ) : null}
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setAuthOpen(true)}
                className={buttonClass}
              >
                Sign In
              </button>
            )}
          </div>
        </div>
      </div>

      <AuthModal open={authOpen} onClose={() => setAuthOpen(false)} initialMode="signup" />
    </div>
  );
}
