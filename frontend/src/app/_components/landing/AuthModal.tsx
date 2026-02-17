"use client";

import {
  ApiError,
  clearTenantDomain,
  getAppleSignInUrl,
  getAppleSignUpUrl,
  getGoogleSignInUrl,
  getGoogleSignUpUrl,
  getTikTokSignInUrl,
  getTikTokSignUpUrl,
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
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { z } from "zod";

/* ------------------------------------------------------------------ */
/*  Zod schemas                                                        */
/* ------------------------------------------------------------------ */

const signUpSchema = z.object({
  name: z.string().min(1, "Name is required").max(100, "Name is too long"),
  email: z.string().min(1, "Email is required").email("Please enter a valid email address"),
  password: z.string().min(8, "Password must be at least 8 characters"),
});

const signInSchema = z.object({
  email: z.string().min(1, "Email is required").email("Please enter a valid email address"),
  password: z.string().min(1, "Password is required"),
});

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Props = {
  open: boolean;
  onClose: () => void;
  initialMode?: "signup" | "signin";
  message?: string;
  error?: string;
};

type SubmitState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "success" }
  | { status: "error"; message: string };

/* ------------------------------------------------------------------ */
/*  Sub-components                                                     */
/* ------------------------------------------------------------------ */

function SocialButton({
  label,
  icon,
  onClick,
  disabled,
}: {
  label: string;
  icon: ReactNode;
  onClick?: () => void;
  disabled?: boolean;
}) {
  return (
    <Button
      type="button"
      variant="outline"
      onClick={onClick}
      disabled={disabled}
      className="h-12 w-full gap-3 rounded-2xl border-white/10 bg-white/5 text-sm font-semibold text-white/90 shadow-none transition hover:bg-white/10 hover:text-white/90 focus-visible:ring-2 focus-visible:ring-fuchsia-400 disabled:opacity-60"
    >
      <span className="grid h-6 w-6 place-items-center text-white/90">{icon}</span>
      <span>{label}</span>
    </Button>
  );
}

function AuthField({
  label,
  name,
  icon,
  type,
  placeholder,
  value,
  onChange,
  right,
  autoComplete,
  disabled,
  error,
}: {
  label: string;
  name: string;
  icon: ReactNode;
  type: string;
  placeholder: string;
  value: string;
  onChange: (value: string) => void;
  right?: ReactNode;
  autoComplete?: string;
  disabled?: boolean;
  error?: string;
}) {
  const errorId = error ? `${name}-error` : undefined;
  return (
    <label className="grid gap-2">
      <span className="text-sm font-semibold text-white/80">{label}</span>
      <span className="relative">
        <span className="pointer-events-none absolute inset-y-0 left-3 grid w-5 place-items-center text-white/45">
          {icon}
        </span>
        <Input
          type={type}
          placeholder={placeholder}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          autoComplete={autoComplete}
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={errorId}
          className="h-12 w-full rounded-2xl border-white/10 bg-black/35 px-10 text-base text-white placeholder:text-white/30 shadow-none transition focus-visible:border-fuchsia-400/60 focus-visible:ring-2 focus-visible:ring-fuchsia-400/15 focus-visible:ring-offset-0 sm:text-sm"
        />
        {right ? <span className="absolute inset-y-0 right-3 grid place-items-center">{right}</span> : null}
      </span>
      {error ? (
        <p id={errorId} className="text-xs text-red-300" role="alert">
          {error}
        </p>
      ) : null}
    </label>
  );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

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

function extractFieldErrors(error: z.core.$ZodError): Record<string, string> {
  const errors: Record<string, string> = {};
  error.issues.forEach((issue) => {
    const field = issue.path[0];
    if (field !== undefined && !errors[String(field)]) {
      errors[String(field)] = issue.message;
    }
  });
  return errors;
}

/* ------------------------------------------------------------------ */
/*  AuthModal                                                          */
/* ------------------------------------------------------------------ */

export default function AuthModal({ open, onClose, initialMode = "signup", message, error }: Props) {
  const titleId = useId();
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
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);

  const resetState = useCallback(() => {
    setMode(initialMode);
    setShowPassword(false);
    setName("");
    setEmail("");
    setPassword("");
    setSubmitState({ status: "idle" });
    setFieldErrors({});
  }, [initialMode]);

  useEffect(() => {
    if (!open) return;
    setMode(initialMode);
    setSubmitState({ status: "idle" });
    setFieldErrors({});
    setBanner(message ?? null);
  }, [initialMode, message, open]);

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

  /* ---------- form helpers ---------- */

  function handleFieldChange(setter: (v: string) => void, fieldName: string) {
    return (value: string) => {
      setter(value);
      if (fieldErrors[fieldName]) {
        setFieldErrors((prev) => {
          const next = { ...prev };
          delete next[fieldName];
          return next;
        });
      }
    };
  }

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (submitState.status === "loading") return;

    setFieldErrors({});

    const schema = mode === "signup" ? signUpSchema : signInSchema;
    const payload =
      mode === "signup"
        ? { name: name.trim(), email: email.trim(), password }
        : { email: email.trim(), password };

    const result = schema.safeParse(payload);
    if (!result.success) {
      setFieldErrors(extractFieldErrors(result.error));
      return;
    }

    setSubmitState({ status: "loading" });

    try {
      const trimmedName = name.trim();
      const spaceIdx = trimmedName.indexOf(" ");
      const firstName = spaceIdx > 0 ? trimmedName.slice(0, spaceIdx) : trimmedName;
      const lastName = spaceIdx > 0 ? trimmedName.slice(spaceIdx + 1).trim() : "";
      const data: AuthSuccessData =
        mode === "signup"
          ? await register({
              name: trimmedName,
              first_name: firstName,
              last_name: lastName,
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

  /* ---------- mobile viewport tracking ---------- */

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

  /* ---------- body overflow lock + Escape key ---------- */

  useEffect(() => {
    if (!open) return;
    const body = document.body;
    const prevOverflow = body.style.overflow;
    body.style.overflow = "hidden";
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") handleClose();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => {
      window.removeEventListener("keydown", onKeyDown);
      body.style.overflow = prevOverflow;
    };
  }, [handleClose, open]);

  /* ---------- custom scrollbar ---------- */

  useEffect(() => {
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

  /* ---------- derived state ---------- */

  const title = mode === "signup" ? "Join the AI magic" : "Welcome back";
  const subtitle =
    mode === "signup"
      ? "Create an account to save your creations and earn credits"
      : "Sign in to continue creating and managing your videos";
  const isBusy = submitState.status === "loading" || submitState.status === "success";
  const canSubmit =
    email.trim().length > 0 && password.length > 0 && (mode === "signin" || name.trim().length > 0);

  async function handleTikTokClick() {
    if (isBusy) return;
    setSubmitState({ status: "loading" });
    try {
      const { url } = mode === "signup" ? await getTikTokSignUpUrl() : await getTikTokSignInUrl();
      window.location.href = url;
    } catch (err) {
      if (err instanceof ApiError) {
        setSubmitState({ status: "error", message: formatAuthError(err) });
      } else {
        setSubmitState({ status: "error", message: "Failed to connect to TikTok." });
      }
    }
  }

  async function handleGoogleClick() {
    if (isBusy) return;
    setSubmitState({ status: "loading" });
    try {
      const { url } = mode === "signup" ? await getGoogleSignUpUrl() : await getGoogleSignInUrl();
      window.location.href = url;
    } catch (err) {
      if (err instanceof ApiError) {
        setSubmitState({ status: "error", message: formatAuthError(err) });
      } else {
        setSubmitState({ status: "error", message: "Failed to connect to Google." });
      }
    }
  }

  async function handleAppleClick() {
    if (isBusy) return;
    setSubmitState({ status: "loading" });
    try {
      const { url } = mode === "signup" ? await getAppleSignUpUrl() : await getAppleSignInUrl();
      window.location.href = url;
    } catch (err) {
      if (err instanceof ApiError) {
        setSubmitState({ status: "error", message: formatAuthError(err) });
      } else {
        setSubmitState({ status: "error", message: "Failed to connect to Apple." });
      }
    }
  }

  /* ---------- render ---------- */

  if (!open) return null;

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
          <Button
            variant="ghost"
            size="icon"
            onClick={handleClose}
            className="absolute right-4 top-3 z-10 h-7 w-7 rounded-full text-white/40 hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-fuchsia-400"
            aria-label="Close"
          >
            <IconX className="h-3.5 w-3.5" />
          </Button>

          <div className="relative overflow-hidden">
            <div ref={scrollRef} className="auth-modal-max-h auth-modal-scroll-body overflow-y-auto p-5 pt-4">
            <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/25 to-violet-500/20 text-fuchsia-200">
              <IconSparkles className="h-7 w-7" />
            </div>

            <h2 id={titleId} className="mt-4 text-center text-2xl font-semibold tracking-tight text-white">
              {title}
            </h2>
            <p className="mt-2 text-center text-sm leading-6 text-white/55">
              {subtitle}
            </p>

            {banner ? (
              <div className="mt-4 rounded-2xl border border-fuchsia-400/20 bg-fuchsia-500/[0.08] px-4 py-3 text-balance text-center text-xs leading-5 text-fuchsia-100/90">
                {banner}
              </div>
            ) : null}

            {error ? (
              <div className="mt-4 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-balance text-center text-xs leading-5 text-red-200">
                {error}
              </div>
            ) : null}

            <div className="mt-6 mx-auto flex w-full max-w-64 rounded-xl bg-white/[0.06] p-1">
              <button
                type="button"
                onClick={() => { setMode("signup"); setSubmitState({ status: "idle" }); setFieldErrors({}); setBanner(null); }}
                className={`flex-1 rounded-[10px] py-2 text-[13px] font-semibold transition-all ${
                  mode === "signup"
                    ? "bg-white/[0.1] text-white shadow-[0_1px_3px_rgba(0,0,0,0.25)]"
                    : "text-white/40 hover:text-white/60"
                }`}
              >
                Sign Up
              </button>
              <button
                type="button"
                onClick={() => { setMode("signin"); setSubmitState({ status: "idle" }); setFieldErrors({}); setBanner(null); }}
                className={`flex-1 rounded-[10px] py-2 text-[13px] font-semibold transition-all ${
                  mode === "signin"
                    ? "bg-white/[0.1] text-white shadow-[0_1px_3px_rgba(0,0,0,0.25)]"
                    : "text-white/40 hover:text-white/60"
                }`}
              >
                Sign In
              </button>
            </div>

            <div className="mt-5 grid gap-3">
              <SocialButton label="Continue with Google" icon={<span className="text-base font-bold">G</span>} onClick={handleGoogleClick} disabled={isBusy} />
              <SocialButton label="Continue with Apple" icon={<IconApple className="h-4 w-4" />} onClick={handleAppleClick} disabled={isBusy} />
              <SocialButton label="Continue with TikTok" icon={<IconMusic className="h-5 w-5" />} onClick={handleTikTokClick} disabled={isBusy} />
            </div>

            <div className="mt-6 flex items-center gap-3">
              <div className="h-px flex-1 bg-white/10" />
              <div className="text-xs font-semibold tracking-widest text-white/45">OR</div>
              <div className="h-px flex-1 bg-white/10" />
            </div>

            <form className="mt-5 grid gap-4" onSubmit={onSubmit} noValidate>
              {mode === "signup" ? (
                <AuthField
                  name="name"
                  label="First and Last Name"
                  icon={<IconUser className="h-5 w-5" />}
                  type="text"
                  placeholder="Jane Smith"
                  value={name}
                  onChange={handleFieldChange(setName, "name")}
                  autoComplete="name"
                  disabled={isBusy}
                  error={fieldErrors.name}
                />
              ) : null}

              <AuthField
                name="email"
                label="Email"
                icon={<IconMail className="h-5 w-5" />}
                type="email"
                placeholder="you@example.com"
                value={email}
                onChange={handleFieldChange(setEmail, "email")}
                autoComplete="email"
                disabled={isBusy}
                error={fieldErrors.email}
              />

              <AuthField
                name="password"
                label="Password"
                icon={<IconLock className="h-5 w-5" />}
                type={showPassword ? "text" : "password"}
                placeholder="••••••••"
                value={password}
                onChange={handleFieldChange(setPassword, "password")}
                autoComplete={mode === "signup" ? "new-password" : "current-password"}
                disabled={isBusy}
                error={fieldErrors.password}
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

              <Button
                type="submit"
                disabled={!canSubmit || isBusy}
                className="mt-1 h-12 w-full rounded-2xl bg-gradient-to-r from-fuchsia-400 to-violet-400 text-sm font-semibold text-black shadow-[0_14px_40px_rgba(236,72,153,0.18)] transition hover:from-fuchsia-300 hover:to-violet-300 focus-visible:ring-2 focus-visible:ring-fuchsia-400 disabled:opacity-70"
              >
                {submitState.status === "loading"
                  ? mode === "signup"
                    ? "Creating…"
                    : "Signing in…"
                  : mode === "signup"
                    ? "Create Account"
                    : "Sign In"}
              </Button>
            </form>

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
