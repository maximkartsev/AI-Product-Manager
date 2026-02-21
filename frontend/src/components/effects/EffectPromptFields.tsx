import { Info } from "lucide-react";
import EffectTextarea from "@/components/effects/EffectTextarea";

type EffectPromptFieldsProps = {
  positivePrompt: string;
  onPositivePromptChange: (value: string) => void;
  negativePrompt: string;
  onNegativePromptChange: (value: string) => void;
};

export default function EffectPromptFields({
  positivePrompt,
  onPositivePromptChange,
  negativePrompt,
  onNegativePromptChange,
}: EffectPromptFieldsProps) {
  return (
    <div className="mt-4 space-y-3">
      <div>
        <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
          <span>Positive prompt</span>
          <button
            type="button"
            className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/70"
            title="Describe what you want to see in the output."
            aria-label="Positive prompt info"
          >
            <Info className="h-3 w-3" />
          </button>
        </div>
        <EffectTextarea
          value={positivePrompt}
          onChange={(event) => onPositivePromptChange(event.target.value)}
          placeholder="Describe the look, style, or details to add..."
          className="mt-2 min-h-[84px] text-xs"
        />
      </div>
      <div>
        <div className="flex items-center justify-between text-[11px] font-semibold text-white/70">
          <span>Negative prompt</span>
          <button
            type="button"
            className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-white/10 bg-white/5 text-white/70"
            title="Describe what you want to avoid."
            aria-label="Negative prompt info"
          >
            <Info className="h-3 w-3" />
          </button>
        </div>
        <EffectTextarea
          value={negativePrompt}
          onChange={(event) => onNegativePromptChange(event.target.value)}
          placeholder="Describe elements to avoid..."
          className="mt-2 min-h-[84px] text-xs"
        />
      </div>
    </div>
  );
}
