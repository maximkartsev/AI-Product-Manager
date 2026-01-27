"use client";

import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { IconApple, IconEye, IconEyeOff, IconLock, IconMail, IconMusic, IconSparkles, IconUser, IconX } from "./icons";

type Props = {
  open: boolean;
  onClose: () => void;
};

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
  right,
}: {
  label: string;
  icon: ReactNode;
  type: string;
  placeholder: string;
  right?: ReactNode;
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
          className="h-12 w-full rounded-2xl border border-white/10 bg-black/35 px-10 text-sm text-white placeholder:text-white/30 outline-none transition focus:border-fuchsia-400/60 focus:ring-2 focus:ring-fuchsia-400/15"
        />
        {right ? <span className="absolute inset-y-0 right-3 grid place-items-center">{right}</span> : null}
      </span>
    </label>
  );
}

export default function AuthModal({ open, onClose }: Props) {
  const titleId = useId();
  const closeRef = useRef<HTMLButtonElement | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [mode, setMode] = useState<"signup" | "signin">("signup");

  useEffect(() => {
    if (!open) return;
    setMode("signup");
    setShowPassword(false);
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const prevOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = "hidden";

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };

    window.addEventListener("keydown", onKeyDown);
    closeRef.current?.focus();

    return () => {
      window.removeEventListener("keydown", onKeyDown);
      document.documentElement.style.overflow = prevOverflow;
    };
  }, [open, onClose]);

  if (!open) return null;

  const title = mode === "signup" ? "Join the AI magic" : "Welcome back";
  const subtitle =
    mode === "signup"
      ? "Create an account to save your creations and earn credits"
      : "Sign in to continue creating and managing your videos";

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <button
        type="button"
        aria-label="Close authentication modal"
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={onClose}
      />

      <div className="relative w-full max-w-md">
        <div className="relative max-h-[calc(100vh-2rem)] overflow-auto rounded-3xl border border-white/10 bg-zinc-950/90 shadow-2xl">
          <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(236,72,153,0.18),transparent_55%),radial-gradient(circle_at_70%_30%,rgba(99,102,241,0.14),transparent_60%)]" />

          <div className="relative p-5">
            <button
              ref={closeRef}
              type="button"
              onClick={onClose}
              className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white/70 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              aria-label="Close"
            >
              <IconX className="h-5 w-5" />
            </button>

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

            <form className="mt-5 grid gap-4" onSubmit={(e) => e.preventDefault()}>
              {mode === "signup" ? (
                <Field label="Name" icon={<IconUser className="h-5 w-5" />} type="text" placeholder="Your name" />
              ) : null}

              <Field label="Email" icon={<IconMail className="h-5 w-5" />} type="email" placeholder="you@example.com" />

              <Field
                label="Password"
                icon={<IconLock className="h-5 w-5" />}
                type={showPassword ? "text" : "password"}
                placeholder="••••••••"
                right={
                  <button
                    type="button"
                    onClick={() => setShowPassword((v) => !v)}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-full text-white/55 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                    aria-label={showPassword ? "Hide password" : "Show password"}
                  >
                    {showPassword ? <IconEyeOff className="h-5 w-5" /> : <IconEye className="h-5 w-5" />}
                  </button>
                }
              />

              <button
                type="submit"
                className="mt-1 inline-flex h-12 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-fuchsia-400 to-violet-400 text-sm font-semibold text-black shadow-[0_14px_40px_rgba(236,72,153,0.18)] transition hover:from-fuchsia-300 hover:to-violet-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
              >
                {mode === "signup" ? "Create Account" : "Sign In"}
              </button>
            </form>

            <div className="mt-4 text-center text-sm text-white/55">
              {mode === "signup" ? (
                <>
                  Already have an account?{" "}
                  <button
                    type="button"
                    onClick={() => setMode("signin")}
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
                    onClick={() => setMode("signup")}
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
        </div>
      </div>
    </div>
  );
}

