import { IconWand } from "@/app/_components/landing/icons";

type EffectUploadFooterProps = {
  label: string;
  disabled: boolean;
  onClick: () => void;
};

export default function EffectUploadFooter({ label, disabled, onClick }: EffectUploadFooterProps) {
  return (
    <div className="fixed inset-x-0 bottom-0 z-40">
      <div className="mx-auto w-full max-w-md px-4 pb-[calc(16px+env(safe-area-inset-bottom))] sm:max-w-xl lg:max-w-4xl">
        <div className="rounded-3xl border border-white/10 bg-black/70 p-2 backdrop-blur-md supports-[backdrop-filter]:bg-black/40">
          <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-fuchsia-500 to-violet-500 text-sm font-semibold text-white shadow-[0_12px_30px_rgba(236,72,153,0.25)] transition hover:from-fuchsia-400 hover:to-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-300 disabled:pointer-events-none disabled:opacity-70"
          >
            <IconWand className="h-5 w-5" />
            {label}
          </button>
        </div>
      </div>
    </div>
  );
}
