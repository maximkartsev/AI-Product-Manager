export type GradientStop = {
  from: string;
  to: string;
};

export const EFFECT_GRADIENTS: GradientStop[] = [
  { from: "from-fuchsia-500", to: "to-cyan-400" },
  { from: "from-amber-400", to: "to-pink-500" },
  { from: "from-sky-400", to: "to-indigo-500" },
  { from: "from-lime-400", to: "to-emerald-500" },
  { from: "from-cyan-400", to: "to-blue-500" },
  { from: "from-fuchsia-500", to: "to-violet-500" },
];

export function hashString(value: string): number {
  let h = 0;
  for (let i = 0; i < value.length; i++) {
    h = (h * 31 + value.charCodeAt(i)) | 0;
  }
  return h;
}

export function gradientForSlug(slug: string): GradientStop {
  const idx = Math.abs(hashString(slug)) % EFFECT_GRADIENTS.length;
  return EFFECT_GRADIENTS[idx]!;
}

export function gradientClass(from: string, to: string): string {
  return `${from} ${to}`;
}
