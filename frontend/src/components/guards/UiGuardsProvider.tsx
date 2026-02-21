"use client";

import { createContext, useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
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
  const token = useAuthToken();
  const [authOpen, setAuthOpen] = useState(false);
  const [plansOpen, setPlansOpen] = useState(false);
  const [requiredTokens, setRequiredTokens] = useState(0);
  const [pendingNavigation, setPendingNavigation] = useState<string | null>(null);
  const [walletState, setWalletState] = useState<WalletState>({ status: "idle" });
  const [authMessage, setAuthMessage] = useState<string | null>(null);
  const [authError, setAuthError] = useState<string | null>(null);

  const openAuth = useCallback(() => setAuthOpen(true), []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);

    if (params.get("auth") === "signup") {
      setAuthMessage("Account not found — sign up to get started with AI Video Effects.");
      openAuth();
      router.replace("/", { scroll: false });
    }

    const errorParam = params.get("auth_error");
    if (errorParam) {
      setAuthError(errorParam);
      openAuth();
      router.replace("/", { scroll: false });
    }
  }, [openAuth, router]);

  const closeAuth = useCallback(() => {
    setAuthOpen(false);
    setAuthMessage(null);
    setAuthError(null);
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
      <AuthModal open={authOpen} onClose={closeAuth} initialMode="signup" message={authMessage ?? undefined} error={authError ?? undefined} />
      <PlansModal
        open={plansOpen}
        onClose={closePlans}
        requiredTokens={requiredTokens}
        balance={walletState.status === "ready" ? walletState.balance : null}
      />
    </UiGuardsContext.Provider>
  );
}
