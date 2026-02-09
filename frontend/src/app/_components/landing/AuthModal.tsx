"use client";

import {
  ApiError,
  clearTenantDomain,
  login,
  register,
  setAccessToken,
  setTenantDomain,
  type AuthSuccessData,
  type LoginRequest,
  type RegisterRequest,
} from "@/lib/api";
import { type FormEvent, useCallback, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { IconApple, IconEye, IconEyeOff, IconLock, IconMail, IconMusic, IconSparkles, IconUser, IconX } from "./icons";

type Props = {
  open: boolean;
  onClose: () => void;
  initialMode?: "signup" | "signin";
};

type SubmitState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "success" }
  | { status: "error"; message: string };

function SocialButton({
  label,
  icon,
}: {
  label: string;
  icon: ReactNode;
}) {
  return (
    <button
      type="button"
      className="flex h-12 w-full items-center justify-center gap-3 rounded-2xl border border-white/10 bg-white/5 text-sm font-semibold text-white/90 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
    >
      <span className="grid h-6 w-6 place-items-center text-white/90">{icon}</span>
      <span>{label}</span>
    </button>
  );
}

function Field({
  label,
  icon,
  type,
  placeholder,
  value,
  onChange,
  right,
  autoComplete,
  disabled,
}: {
  label: string;
  icon: ReactNode;
  type: string;
  placeholder: string;
  value: string;
  onChange: (value: string) => void;
  right?: ReactNode;
  autoComplete?: string;
  disabled?: boolean;
}) {
  return (
    <label className="grid gap-2">
      <span className="text-sm font-semibold text-white/80">{label}</span>
      <span className="relative">
        <span className="pointer-events-none absolute inset-y-0 left-3 grid w-5 place-items-center text-white/45">
          {icon}
        </span>
        <input
          type={type}
          placeholder={placeholder}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          autoComplete={autoComplete}
          disabled={disabled}
          className="h-12 w-full rounded-2xl border border-white/10 bg-black/35 px-10 text-base text-white placeholder:text-white/30 outline-none transition focus:border-fuchsia-400/60 focus:ring-2 focus:ring-fuchsia-400/15 sm:text-sm"
        />
        {right ? <span className="absolute inset-y-0 right-3 grid place-items-center">{right}</span> : null}
      </span>
    </label>
  );
}

function formatAuthError(err: ApiError): string {
  const base = `${err.status ? `HTTP ${err.status}: ` : ""}${err.message}`.trim() || "Authentication failed.";

  const payload = err.data as unknown;
  if (!payload || typeof payload !== "object") return base;
  if (!("data" in payload)) return base;

  const data = (payload as { data?: unknown }).data;
  if (!data || typeof data !== "object") return base;

  const firstField = Object.keys(data as Record<string, unknown>)[0];
  if (!firstField) return base;

  const fieldValue = (data as Record<string, unknown>)[firstField];
  const firstMessage =
    Array.isArray(fieldValue) ? fieldValue[0] : typeof fieldValue === "string" ? fieldValue : null;

  if (!firstMessage) return base;
  return `${base} (${firstField}: ${String(firstMessage)})`;
}

export default function AuthModal({ open, onClose, initialMode = "signup" }: Props) {
  const titleId = useId();
  const closeRef = useRef<HTMLButtonElement | null>(null);
  const viewportRef = useRef<HTMLDivElement | null>(null);
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const scrollTrackRef = useRef<HTMLDivElement | null>(null);
  const scrollThumbRef = useRef<HTMLDivElement | null>(null);
  const dragRef = useRef<{
    startY: number;
    startScrollTop: number;
    scrollPerPx: number;
  } | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [mode, setMode] = useState<"signup" | "signin">(initialMode);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [submitState, setSubmitState] = useState<SubmitState>({ status: "idle" });

  const resetState = useCallback(() => {
    setMode(initialMode);
    setShowPassword(false);
    setName("");
    setEmail("");
    setPassword("");
    setSubmitState({ status: "idle" });
  }, [initialMode]);

  useEffect(() => {
    if (!open) return;
    setMode(initialMode);
    setSubmitState({ status: "idle" });
  }, [initialMode, open]);

  const handleClose = useCallback(() => {
    onClose();
    resetState();
  }, [onClose, resetState]);

  useEffect(() => {
    if (!open) return;
    if (submitState.status !== "success") return;

    const t = window.setTimeout(() => handleClose(), 400);
    return () => window.clearTimeout(t);
  }, [handleClose, open, submitState.status]);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (submitState.status === "loading") return;

    setSubmitState({ status: "loading" });

    try {
      const data: AuthSuccessData =
        mode === "signup"
          ? await register({
              name: name.trim(),
              email: email.trim(),
              password,
              c_password: password,
            } satisfies RegisterRequest)
          : await login({
              email: email.trim(),
              password,
            } satisfies LoginRequest);

      setAccessToken(data.token);
      if (data.tenant?.domain) {
        setTenantDomain(data.tenant.domain);
      } else {
        clearTenantDomain();
      }

      setSubmitState({ status: "success" });
    } catch (err) {
      if (err instanceof ApiError) {
        setSubmitState({ status: "error", message: formatAuthError(err) });
        return;
      }

      setSubmitState({ status: "error", message: "Unexpected error while authenticating." });
    }
  }

  useEffect(() => {
    if (!open) return;

    const body = document.body;
    const prevOverflow = body.style.overflow;
    body.style.overflow = "hidden";

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") handleClose();
    };

    window.addEventListener("keydown", onKeyDown);
    closeRef.current?.focus();

    return () => {
      window.removeEventListener("keydown", onKeyDown);
      body.style.overflow = prevOverflow;
    };
  }, [handleClose, open]);

  useEffect(() => {
    if (!open) return;
    const target = viewportRef.current;
    const viewport = window.visualViewport;
    if (!target || !viewport) return;

    const update = () => {
      target.style.setProperty("--auth-modal-vh", `${Math.round(viewport.height)}px`);
      target.style.setProperty("--auth-modal-offset-top", `${Math.round(viewport.offsetTop)}px`);
    };

    update();
    viewport.addEventListener("resize", update);
    viewport.addEventListener("scroll", update);
    window.addEventListener("orientationchange", update);

    return () => {
      viewport.removeEventListener("resize", update);
      viewport.removeEventListener("scroll", update);
      window.removeEventListener("orientationchange", update);
      target.style.removeProperty("--auth-modal-vh");
      target.style.removeProperty("--auth-modal-offset-top");
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const el = scrollRef.current;
    const track = scrollTrackRef.current;
    const thumb = scrollThumbRef.current;
    if (!el || !thumb || !track) return;

    let frame = 0;
    const update = () => {
      const scrollHeight = el.scrollHeight;
      const clientHeight = el.clientHeight;
      const maxScroll = scrollHeight - clientHeight;

      if (maxScroll <= 0) {
        track.style.opacity = "0";
        track.style.pointerEvents = "none";
        thumb.style.opacity = "0";
        thumb.style.height = "0px";
        thumb.style.transform = "translateY(0)";
        return;
      }

      track.style.opacity = "1";
      track.style.pointerEvents = "auto";

      const minThumb = 36;
      const trackHeight = track.clientHeight;
      const thumbHeight = Math.max(minThumb, (clientHeight / scrollHeight) * trackHeight);
      const maxThumbTop = Math.max(0, trackHeight - thumbHeight);
      const thumbTop = maxScroll > 0 ? (el.scrollTop / maxScroll) * maxThumbTop : 0;

      thumb.style.opacity = "1";
      thumb.style.height = `${thumbHeight}px`;
      thumb.style.transform = `translateY(${thumbTop}px)`;
    };

    const onScroll = () => {
      if (frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        update();
      });
    };

    update();
    el.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", update);

    const resizeObserver = new ResizeObserver(() => update());
    resizeObserver.observe(el);
    if (el.firstElementChild) resizeObserver.observe(el.firstElementChild);

    return () => {
      if (frame) window.cancelAnimationFrame(frame);
      el.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", update);
      resizeObserver.disconnect();
    };
  }, [open, mode, submitState.status]);

  const handleThumbPointerDown = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const el = scrollRef.current;
    const track = scrollTrackRef.current;
    const thumb = scrollThumbRef.current;
    if (!el || !track || !thumb) return;

    const scrollHeight = el.scrollHeight;
    const clientHeight = el.clientHeight;
    const maxScroll = Math.max(0, scrollHeight - clientHeight);
    const trackHeight = track.clientHeight;
    const thumbHeight = thumb.getBoundingClientRect().height;
    const maxThumbTop = Math.max(0, trackHeight - thumbHeight);
    const scrollPerPx = maxThumbTop > 0 ? maxScroll / maxThumbTop : 0;

    dragRef.current = {
      startY: event.clientY,
      startScrollTop: el.scrollTop,
      scrollPerPx,
    };

    thumb.setPointerCapture(event.pointerId);
    event.preventDefault();
  }, []);

  const handleThumbPointerMove = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current;
    const el = scrollRef.current;
    if (!drag || !el) return;
    el.scrollTop = drag.startScrollTop + (event.clientY - drag.startY) * drag.scrollPerPx;
  }, []);

  const handleThumbPointerUp = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const thumb = scrollThumbRef.current;
    dragRef.current = null;
    if (thumb && thumb.hasPointerCapture(event.pointerId)) {
      thumb.releasePointerCapture(event.pointerId);
    }
  }, []);

  const handleTrackPointerDown = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    if (event.target === scrollThumbRef.current) return;
    const el = scrollRef.current;
    const track = scrollTrackRef.current;
    const thumb = scrollThumbRef.current;
    if (!el || !track || !thumb) return;

    const trackRect = track.getBoundingClientRect();
    const thumbHeight = thumb.getBoundingClientRect().height;
    const maxScroll = Math.max(0, el.scrollHeight - el.clientHeight);
    const maxThumbTop = Math.max(0, trackRect.height - thumbHeight);
    if (maxThumbTop <= 0) return;

    const offset = event.clientY - trackRect.top - thumbHeight / 2;
    const clamped = Math.max(0, Math.min(maxThumbTop, offset));
    el.scrollTop = (clamped / maxThumbTop) * maxScroll;
  }, []);

  if (!open) return null;

  const title = mode === "signup" ? "Join the AI magic" : "Welcome back";
  const subtitle =
    mode === "signup"
      ? "Create an account to save your creations and earn credits"
      : "Sign in to continue creating and managing your videos";
  const isBusy = submitState.status === "loading" || submitState.status === "success";
  const canSubmit =
    email.trim().length > 0 && password.length > 0 && (mode === "signin" || name.trim().length > 0);

  return (
    <div
      ref={viewportRef}
      className="auth-modal-viewport fixed inset-0 z-50 flex items-start justify-center p-4 sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
    >
      <button
        type="button"
        aria-label="Close authentication modal"
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={handleClose}
      />

      <div className="relative w-full max-w-md">
        <div className="auth-modal-max-h relative overflow-hidden rounded-3xl border border-white/10 bg-zinc-950/90 shadow-2xl">
          <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(236,72,153,0.18),transparent_55%),radial-gradient(circle_at_70%_30%,rgba(99,102,241,0.14),transparent_60%)]" />

          <div className="relative overflow-hidden">
            <div ref={scrollRef} className="auth-modal-max-h auth-modal-scroll-body overflow-y-auto p-5 pr-7">
            <div className="flex justify-end">
              <button
                ref={closeRef}
                type="button"
                onClick={handleClose}
                className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white/70 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                aria-label="Close"
              >
                <IconX className="h-5 w-5" />
              </button>
            </div>

            <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/25 to-violet-500/20 text-fuchsia-200">
              <IconSparkles className="h-7 w-7" />
            </div>

            <h2 id={titleId} className="mt-4 text-center text-2xl font-semibold tracking-tight text-white">
              {title}
            </h2>
            <p className="mt-2 text-center text-sm leading-6 text-white/55">{subtitle}</p>

            <div className="mt-6 grid gap-3">
              <SocialButton label="Continue with Google" icon={<span className="text-base font-bold">G</span>} />
              <SocialButton label="Continue with Apple" icon={<IconApple className="h-4 w-4" />} />
              <SocialButton label="Continue with TikTok" icon={<IconMusic className="h-5 w-5" />} />
            </div>

            <div className="mt-6 flex items-center gap-3">
              <div className="h-px flex-1 bg-white/10" />
              <div className="text-xs font-semibold tracking-widest text-white/45">OR</div>
              <div className="h-px flex-1 bg-white/10" />
            </div>

            <form className="mt-5 grid gap-4" onSubmit={onSubmit}>
              {mode === "signup" ? (
                <Field
                  label="Name"
                  icon={<IconUser className="h-5 w-5" />}
                  type="text"
                  placeholder="Your name"
                  value={name}
                  onChange={setName}
                  autoComplete="name"
                  disabled={isBusy}
                />
              ) : null}

              <Field
                label="Email"
                icon={<IconMail className="h-5 w-5" />}
                type="email"
                placeholder="you@example.com"
                value={email}
                onChange={setEmail}
                autoComplete="email"
                disabled={isBusy}
              />

              <Field
                label="Password"
                icon={<IconLock className="h-5 w-5" />}
                type={showPassword ? "text" : "password"}
                placeholder="••••••••"
                value={password}
                onChange={setPassword}
                autoComplete={mode === "signup" ? "new-password" : "current-password"}
                disabled={isBusy}
                right={
                  <button
                    type="button"
                    onClick={() => setShowPassword((v) => !v)}
                    disabled={isBusy}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-full text-white/55 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400 disabled:opacity-60"
                    aria-label={showPassword ? "Hide password" : "Show password"}
                  >
                    {showPassword ? <IconEyeOff className="h-5 w-5" /> : <IconEye className="h-5 w-5" />}
                  </button>
                }
              />

              {submitState.status === "error" ? (
                <div className="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-xs text-red-200">
                  {submitState.message}
                </div>
              ) : submitState.status === "success" ? (
                <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-xs text-emerald-200">
                  Success! Closing…
                </div>
              ) : null}

              <button
                type="submit"
                disabled={!canSubmit || isBusy}
                className="mt-1 inline-flex h-12 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-fuchsia-400 to-violet-400 text-sm font-semibold text-black shadow-[0_14px_40px_rgba(236,72,153,0.18)] transition hover:from-fuchsia-300 hover:to-violet-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400 disabled:pointer-events-none disabled:opacity-70"
              >
                {submitState.status === "loading"
                  ? mode === "signup"
                    ? "Creating…"
                    : "Signing in…"
                  : mode === "signup"
                    ? "Create Account"
                    : "Sign In"}
              </button>
            </form>

            <div className="mt-4 text-center text-sm text-white/55">
              {mode === "signup" ? (
                <>
                  Already have an account?{" "}
                  <button
                    type="button"
                    onClick={() => {
                      setMode("signin");
                      setSubmitState({ status: "idle" });
                    }}
                    className="font-semibold text-fuchsia-300 transition hover:text-fuchsia-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                  >
                    Sign in
                  </button>
                </>
              ) : (
                <>
                  Don&apos;t have an account?{" "}
                  <button
                    type="button"
                    onClick={() => {
                      setMode("signup");
                      setSubmitState({ status: "idle" });
                    }}
                    className="font-semibold text-fuchsia-300 transition hover:text-fuchsia-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                  >
                    Create one
                  </button>
                </>
              )}
            </div>

            <p className="mt-4 text-center text-[11px] leading-4 text-white/40">
              By signing up, you agree to our{" "}
              <a href="#" className="font-medium text-fuchsia-200 hover:text-fuchsia-100">
                Terms
              </a>{" "}
              and{" "}
              <a href="#" className="font-medium text-fuchsia-200 hover:text-fuchsia-100">
                Privacy Policy
              </a>
              .
            </p>
            </div>
            <div className="auth-modal-scrollbar pointer-events-auto absolute right-1 top-8 bottom-4 w-2">
              <div
                ref={scrollTrackRef}
                onPointerDown={handleTrackPointerDown}
                className="relative h-full w-full rounded-full bg-white/5"
              >
                <div
                  ref={scrollThumbRef}
                  onPointerDown={handleThumbPointerDown}
                  onPointerMove={handleThumbPointerMove}
                  onPointerUp={handleThumbPointerUp}
                  className="absolute left-0 top-0 w-full rounded-full bg-gradient-to-b from-fuchsia-300/22 to-violet-300/22"
                  style={{ touchAction: "none" }}
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

