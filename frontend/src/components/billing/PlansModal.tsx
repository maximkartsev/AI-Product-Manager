"use client";

type Plan = {
  id: string;
  name: string;
  price: string;
  tokens: string;
  description: string;
};

const PLANS: Plan[] = [
  {
    id: "starter",
    name: "Starter",
    price: "$9",
    tokens: "50 tokens",
    description: "Great for trying a few effects and sharing.",
  },
  {
    id: "creator",
    name: "Creator",
    price: "$19",
    tokens: "150 tokens",
    description: "Best value for active creators and weekly uploads.",
  },
  {
    id: "studio",
    name: "Studio",
    price: "$39",
    tokens: "400 tokens",
    description: "For teams and high-volume production pipelines.",
  },
];

export default function PlansModal({
  open,
  onClose,
  requiredTokens,
  balance,
}: {
  open: boolean;
  onClose: () => void;
  requiredTokens: number;
  balance?: number | null;
}) {
  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="plans-title"
    >
      <button
        type="button"
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={onClose}
        aria-label="Close plans dialog"
      />
      <div className="relative w-full max-w-md">
        <div className="relative rounded-3xl border border-white/10 bg-zinc-950/90 p-5 shadow-2xl">
          <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(236,72,153,0.18),transparent_55%),radial-gradient(circle_at_70%_30%,rgba(99,102,241,0.14),transparent_60%)]" />
          <div className="relative">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h2 id="plans-title" className="text-xl font-semibold text-white">
                  Not enough tokens
                </h2>
                <p className="mt-1 text-xs text-white/60">
                  This effect costs {requiredTokens} tokens.{" "}
                  {balance !== null && balance !== undefined
                    ? `Your balance is ${balance} tokens.`
                    : "Sign in to view your balance."}
                </p>
              </div>
              <button
                type="button"
                onClick={onClose}
                className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/5 text-white/70 transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400"
                aria-label="Close"
              >
                X
              </button>
            </div>

            <div className="mt-4 grid gap-3">
              {PLANS.map((plan) => (
                <div key={plan.id} className="rounded-2xl border border-white/10 bg-white/5 p-4">
                  <div className="flex items-center justify-between">
                    <div className="text-sm font-semibold text-white">{plan.name}</div>
                    <div className="text-sm font-semibold text-fuchsia-200">{plan.price}</div>
                  </div>
                  <div className="mt-1 text-xs text-white/60">{plan.tokens}</div>
                  <div className="mt-2 text-xs text-white/50">{plan.description}</div>
                  <button
                    type="button"
                    className="mt-3 inline-flex h-9 w-full items-center justify-center rounded-xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-xs font-semibold text-white shadow-[0_10px_24px_rgba(124,58,237,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 disabled:opacity-70"
                    disabled
                  >
                    Coming soon
                  </button>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
