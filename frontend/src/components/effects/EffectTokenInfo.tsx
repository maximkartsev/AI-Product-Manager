type EffectTokenInfoProps = {
  creditsCost: number;
  walletBalance: number | null;
  hasEnoughTokens: boolean;
  isAuthenticated: boolean;
  onTopUp: () => void;
};

export default function EffectTokenInfo({
  creditsCost,
  walletBalance,
  hasEnoughTokens,
  isAuthenticated,
  onTopUp,
}: EffectTokenInfoProps) {
  return (
    <div className="mt-3 rounded-2xl border border-white/10 bg-black/25 px-3 py-2 text-[11px] text-white/65">
      <div>Cost: {creditsCost} tokens</div>
      {isAuthenticated ? (
        walletBalance !== null ? (
          <div>Balance: {walletBalance} tokens</div>
        ) : (
          <div>Balance: --</div>
        )
      ) : (
        <div>Sign in to view your balance.</div>
      )}
      {isAuthenticated && walletBalance !== null && !hasEnoughTokens && creditsCost > 0 ? (
        <div className="mt-2 flex items-center justify-between gap-3">
          <div className="text-amber-200">Not enough tokens to upload.</div>
          <button
            type="button"
            onClick={onTopUp}
            className="inline-flex h-7 items-center justify-center rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500 px-3 text-[11px] font-semibold text-white shadow-[0_10px_20px_rgba(124,58,237,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400"
          >
            Top up tokens
          </button>
        </div>
      ) : null}
    </div>
  );
}
