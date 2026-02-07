export type Gradient = {
  from: string;
  to: string;
};

export type Effect = {
  id: string;
  name: string;
  tagline: string;
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
  stats: {
    likes: string;
    views: string;
  };
  gradient: Gradient;
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

export const popularEffects: Effect[] = [
  {
    id: "neon-glow",
    name: "Neon Glow",
    tagline: "Add vibrant neon outlines",
    stats: { uses: "12.5K uses" },
    gradient: { from: "from-fuchsia-500", to: "to-cyan-400" },
  },
  {
    id: "comic-book",
    name: "Comic Book",
    tagline: "Transform into a comic strip",
    stats: { uses: "8.1K uses" },
    gradient: { from: "from-amber-400", to: "to-pink-500" },
  },
  {
    id: "snowy-winter",
    name: "Snowy Winter",
    tagline: "Snow overlay + cool tones",
    stats: { uses: "6.3K uses" },
    gradient: { from: "from-sky-400", to: "to-indigo-500" },
  },
  {
    id: "anime-style",
    name: "Anime Style",
    tagline: "Anime-inspired look in seconds",
    stats: { uses: "9.9K uses" },
    gradient: { from: "from-lime-400", to: "to-emerald-500" },
  },
];

export const publicGallery: GalleryItem[] = [
  {
    id: "g-neon",
    title: "Neon Dance Vibes",
    effect: "Neon Glow",
    stats: { likes: "2,563", views: "128.9K views" },
    gradient: { from: "from-fuchsia-500", to: "to-cyan-400" },
  },
  {
    id: "g-snow",
    title: "Winter Wonderland",
    effect: "Snowy Winter",
    stats: { likes: "4,852", views: "142.1K views" },
    gradient: { from: "from-sky-400", to: "to-indigo-500" },
  },
  {
    id: "g-comic",
    title: "Comic Hero",
    effect: "Comic Book",
    stats: { likes: "9,112", views: "291.2K views" },
    gradient: { from: "from-amber-400", to: "to-pink-500" },
  },
  {
    id: "g-anime",
    title: "Anime Style",
    effect: "Anime Style",
    stats: { likes: "6,329", views: "211.7K views" },
    gradient: { from: "from-lime-400", to: "to-emerald-500" },
  },
];

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

