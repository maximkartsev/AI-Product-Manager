"use client";

import type { ApiEffect } from "@/lib/api";
import { EffectCard } from "@/components/cards/EffectCard";

export default function EffectGridCard({ effect, onOpen }: { effect: ApiEffect; onOpen: () => void }) {
  return <EffectCard variant="effectsGrid" effect={effect} onOpen={onOpen} />;
}
