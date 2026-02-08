"use client";

import { useState } from "react";
import EffectsFeedClient from "./EffectsFeedClient";
import EffectsGridClient from "./EffectsGridClient";
import SegmentedToggle from "@/components/ui/SegmentedToggle";

export default function EffectsLibraryClient() {
  const [viewMode, setViewMode] = useState<"grid" | "category">("category");

  return (
    <div className="min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl">
        <section className="mt-6">
          <h1 className="text-2xl font-semibold tracking-tight text-white sm:text-3xl">All effects</h1>
          <p className="mt-2 text-sm text-white/60">
            Browse the most popular effects, then explore by category.
          </p>
        </section>

        <div className="mt-6 flex items-center justify-between gap-3">
          <div className="text-xs text-white/55">Browse</div>
          <SegmentedToggle
            value={viewMode}
            onChange={setViewMode}
            options={[
              { id: "category", label: "By category" },
              { id: "grid", label: "Grid" },
            ]}
          />
        </div>

        {viewMode === "category" ? <EffectsFeedClient /> : <EffectsGridClient />}
      </div>
    </div>
  );
}
