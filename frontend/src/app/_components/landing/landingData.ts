export type Gradient = {
  from: string;
  to: string;
};

export type Effect = {
  id: string;
  name: string;
  tagline: string;
  type?: string | null;
  is_premium?: boolean;
  thumbnail_url?: string | null;
  badge?: string;
  stats: {
    uses: string;
  };
  gradient: Gradient;
};

export type GalleryItem = {
  id: string;
  title: string;
  effect: string;
  stats?: {
    likes?: string;
    views?: string;
  };
  gradient: Gradient;
  thumbnail_url?: string | null;
  processed_file_url?: string | null;
  effect_slug?: string | null;
  effect_type?: string | null;
};

export type Feature = {
  id: string;
  title: string;
  description: string;
};

export const brand = {
  name: "DZZZS",
  tagline: "AI Video Effects Studio",
};

export const hero = {
  effectLabel: "Neon Glow Effect",
  effectDescription: "Turn a simple outfit into neon‑outlined magic.",
  badge: "AI‑Powered Video Magic",
  headline: "Turn your videos into\nviral AI creations",
  description:
    "One‑click AI effects that transform your ordinary clips into eye‑catching content. No editing skills required.",
  socialProof: {
    headline: "Used by 1M+ creators",
  },
};

export const features: Feature[] = [
  {
    id: "instant-processing",
    title: "Instant Processing",
    description: "AI transforms your videos in seconds, not hours. Just upload and watch the magic happen.",
  },
  {
    id: "one-click-effects",
    title: "One‑Click Effects",
    description: "No editing skills needed. Choose an effect and we handle all the complex AI processing.",
  },
  {
    id: "viral-ready",
    title: "Viral Ready",
    description: "Share directly to TikTok, Instagram, or download. Every video is optimized for social.",
  },
  {
    id: "upgrade-pro",
    title: "Upgrade to Go Pro",
    description: "Remove watermarks, unlock premium effects, and export in full HD quality.",
  },
];

export const trustBadges = ["Instant start", "No credit card", "Instant results"] as const;

