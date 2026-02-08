"use client";

import { cn } from "@/lib/utils";

export type SegmentedOption<T extends string> = {
  id: T;
  label: string;
};

type SegmentedToggleProps<T extends string> = {
  value: T;
  onChange: (value: T) => void;
  options: SegmentedOption<T>[];
  className?: string;
};

export default function SegmentedToggle<T extends string>({
  value,
  onChange,
  options,
  className,
}: SegmentedToggleProps<T>) {
  return (
    <div
      className={cn(
        "inline-flex rounded-full border border-white/10 bg-white/5 p-1 text-[11px] font-semibold",
        className,
      )}
      role="tablist"
    >
      {options.map((option) => {
        const active = option.id === value;
        return (
          <button
            key={option.id}
            type="button"
            onClick={() => onChange(option.id)}
            className={cn(
              "rounded-full px-3 py-1 transition",
              active ? "bg-white text-black" : "text-white/70 hover:text-white",
            )}
            role="tab"
            aria-selected={active}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

