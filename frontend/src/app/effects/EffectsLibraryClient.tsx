"use client";

import { useState } from "react";
import EffectsFeedClient from "./EffectsFeedClient";
import EffectsGridClient from "./EffectsGridClient";
import SegmentedToggle from "@/components/ui/SegmentedToggle";
import { IconSparkles } from "@/app/_components/landing/icons";

export default function EffectsLibraryClient() {
  const [viewMode, setViewMode] = useState<"grid" | "category">("category");

  return (
    <div className="noise-overlay min-h-screen bg-[#05050a] font-sans text-white selection:bg-fuchsia-500/30 selection:text-white">
      <div className="relative mx-auto w-full max-w-md px-4 py-6 sm:max-w-xl lg:max-w-4xl">
        {/* Ambient background glows */}
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
          <div
            className="absolute -left-32 top-12 h-64 w-64 rounded-full bg-fuchsia-600/15 blur-[100px]"
            style={{ animation: "glow-drift 14s ease-in-out infinite" }}
          />
          <div
            className="absolute -right-20 top-72 h-48 w-48 rounded-full bg-violet-600/10 blur-[80px]"
            style={{ animation: "glow-drift-reverse 16s ease-in-out infinite" }}
          />
          <div
            className="absolute left-1/3 top-[55%] h-40 w-40 rounded-full bg-cyan-500/[0.07] blur-[90px]"
            style={{ animation: "glow-drift 18s ease-in-out infinite 3s" }}
          />
        </div>

        {/* Page header */}
        <section className="effects-entrance relative">
          <div className="flex items-center gap-3">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-fuchsia-500/25 to-violet-500/20">
              <IconSparkles className="h-5 w-5 text-fuchsia-200" />
            </span>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">
                All{" "}
                <span className="bg-gradient-to-r from-fuchsia-400 via-violet-400 to-cyan-400 bg-clip-text text-transparent">
                  effects
                </span>
              </h1>
              <p className="mt-0.5 text-sm text-white/50">
                Browse the most popular effects, then explore by category.
              </p>
            </div>
          </div>
        </section>

        {/* Browse toggle row */}
        <div className="effects-entrance effects-entrance-d1 relative mt-6 flex items-center justify-between gap-3">
          <div className="flex items-center gap-2.5">
            <span
              className="h-1 w-5 rounded-full bg-gradient-to-r from-fuchsia-500 to-violet-500"
              aria-hidden="true"
            />
            <span className="text-xs font-medium text-white/55">Browse</span>
          </div>
          <SegmentedToggle
            value={viewMode}
            onChange={setViewMode}
            options={[
              { id: "category", label: "By category" },
              { id: "grid", label: "Grid" },
            ]}
          />
        </div>

        {/* Content */}
        <div className="effects-entrance effects-entrance-d2 relative">
          {viewMode === "category" ? <EffectsFeedClient /> : <EffectsGridClient />}
        </div>
      </div>
    </div>
  );
}
