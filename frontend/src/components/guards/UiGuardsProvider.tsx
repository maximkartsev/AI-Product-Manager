"use client";

import { createContext, useCallback, useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { UserPlus, X } from "lucide-react";
import AuthModal from "@/app/_components/landing/AuthModal";
import PlansModal from "@/components/billing/PlansModal";
import { ApiError, getAccessToken, getWallet } from "@/lib/api";
import useAuthToken from "@/lib/useAuthToken";

type WalletState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "ready"; balance: number }
  | { status: "error"; message: string };

export type UiGuardsContextValue = {
  requireAuth: () => boolean;
  openAuth: () => void;
  requireAuthForNavigation: (href: string) => boolean;
  ensureTokens: (requiredTokens: number) => Promise<boolean>;
  loadWalletBalance: () => Promise<number | null>;
  openPlans: (requiredTokens: number) => void;
  walletBalance: number | null;
};

export const UiGuardsContext = createContext<UiGuardsContextValue | null>(null);

export default function UiGuardsProvider({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = useAuthToken();
  const [authOpen, setAuthOpen] = useState(false);
  const [plansOpen, setPlansOpen] = useState(false);
  const [requiredTokens, setRequiredTokens] = useState(0);
  const [pendingNavigation, setPendingNavigation] = useState<string | null>(null);
  const [walletState, setWalletState] = useState<WalletState>({ status: "idle" });

  const openAuth = useCallback(() => setAuthOpen(true), []);

  useEffect(() => {
    if (searchParams.get("auth") === "signup") {
      openAuth();
      toast.custom(
        (id) => (
          <div
            style={{
              position: "relative",
              overflow: "hidden",
              background: "rgba(9,9,11,0.9)",
              border: "1px solid rgba(255,255,255,0.1)",
              borderRadius: "1.5rem",
              padding: "20px 16px",
              display: "flex",
              alignItems: "flex-start",
              gap: "12px",
              fontFamily: "var(--font-geist-sans), sans-serif",
              boxShadow: "0 25px 50px -12px rgba(0,0,0,0.25)",
              maxWidth: "28rem",
              width: "100%",
            }}
          >
            {/* Fuchsia/indigo radial glow overlay */}
            <div
              aria-hidden
              style={{
                position: "absolute",
                inset: 0,
                background:
                  "radial-gradient(ellipse at 30% 0%, rgba(217,70,239,0.08) 0%, transparent 60%), radial-gradient(ellipse at 70% 100%, rgba(99,102,241,0.06) 0%, transparent 60%)",
                pointerEvents: "none",
              }}
            />
            <div
              style={{
                position: "relative",
                background:
                  "linear-gradient(to bottom right, rgba(217,70,239,0.25), rgba(139,92,246,0.2))",
                borderRadius: "12px",
                padding: "8px",
                flexShrink: 0,
                marginTop: "1px",
              }}
            >
              <UserPlus size={16} color="#e879f9" strokeWidth={2} />
            </div>
            <div style={{ position: "relative", flex: 1, minWidth: 0 }}>
              <p
                style={{
                  margin: 0,
                  fontSize: "14px",
                  fontWeight: 600,
                  color: "#ffffff",
                  lineHeight: "1.4",
                }}
              >
                Account not found
              </p>
              <p
                style={{
                  margin: "4px 0 0",
                  fontSize: "12px",
                  color: "rgba(255,255,255,0.55)",
                  lineHeight: "1.4",
                }}
              >
                Sign up to get started with AI Video Effects.
              </p>
            </div>
            <button
              onClick={() => toast.dismiss(id)}
              style={{
                position: "relative",
                background: "none",
                border: "none",
                padding: "4px",
                cursor: "pointer",
                color: "rgba(255,255,255,0.4)",
                flexShrink: 0,
                borderRadius: "9999px",
                transition: "background 0.15s, color 0.15s",
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.background = "rgba(255,255,255,0.1)";
                e.currentTarget.style.color = "#ffffff";
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.background = "none";
                e.currentTarget.style.color = "rgba(255,255,255,0.4)";
              }}
              aria-label="Dismiss"
            >
              <X size={14} strokeWidth={2} />
            </button>
          </div>
        ),
        { duration: 6000 },
      );
      router.replace("/", { scroll: false });
    }
  }, [searchParams, openAuth, router]);

  const closeAuth = useCallback(() => {
    setAuthOpen(false);
    if (!getAccessToken()) {
      setPendingNavigation(null);
    }
  }, []);

  const openPlans = useCallback((tokens: number) => {
    const normalized = Math.max(0, Math.ceil(Number(tokens ?? 0)));
    setRequiredTokens(normalized);
    setPlansOpen(true);
  }, []);

  const closePlans = useCallback(() => setPlansOpen(false), []);

  const ensureWalletBalance = useCallback(async (): Promise<number | null> => {
    const activeToken = token ?? getAccessToken();
    if (!activeToken) return null;
    if (walletState.status === "ready") return walletState.balance;

    setWalletState({ status: "loading" });
    try {
      const data = await getWallet();
      setWalletState({ status: "ready", balance: data.balance });
      return data.balance;
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Unable to load token balance.";
      setWalletState({ status: "error", message });
      return null;
    }
  }, [token, walletState.status, walletState.status === "ready" ? walletState.balance : null]);

  const requireAuth = useCallback(() => {
    const activeToken = token ?? getAccessToken();
    if (activeToken) return true;
    openAuth();
    return false;
  }, [openAuth, token]);

  const requireAuthForNavigation = useCallback(
    (href: string) => {
      const activeToken = token ?? getAccessToken();
      if (activeToken) {
        router.push(href);
        return true;
      }
      setPendingNavigation(href);
      openAuth();
      return false;
    },
    [openAuth, router, token],
  );

  const ensureTokens = useCallback(
    async (tokens: number) => {
      const required = Math.max(0, Math.ceil(Number(tokens ?? 0)));
      if (required <= 0) return true;

      const activeToken = token ?? getAccessToken();
      if (!activeToken) {
        openAuth();
        return false;
      }

      const balance = await ensureWalletBalance();
      if (balance === null) return false;
      if (balance < required) {
        openPlans(required);
        return false;
      }
      return true;
    },
    [ensureWalletBalance, openAuth, openPlans, token],
  );

  const loadWalletBalance = useCallback(() => ensureWalletBalance(), [ensureWalletBalance]);

  useEffect(() => {
    if (!token || !pendingNavigation) return;
    router.push(pendingNavigation);
    setPendingNavigation(null);
    setAuthOpen(false);
  }, [pendingNavigation, router, token]);

  const value = useMemo<UiGuardsContextValue>(
    () => ({
      requireAuth,
      openAuth,
      requireAuthForNavigation,
      ensureTokens,
      loadWalletBalance,
      openPlans,
      walletBalance: walletState.status === "ready" ? walletState.balance : null,
    }),
    [ensureTokens, loadWalletBalance, openAuth, openPlans, requireAuth, requireAuthForNavigation, walletState],
  );

  return (
    <UiGuardsContext.Provider value={value}>
      {children}
      <AuthModal open={authOpen} onClose={closeAuth} initialMode="signup" />
      <PlansModal
        open={plansOpen}
        onClose={closePlans}
        requiredTokens={requiredTokens}
        balance={walletState.status === "ready" ? walletState.balance : null}
      />
    </UiGuardsContext.Provider>
  );
}
