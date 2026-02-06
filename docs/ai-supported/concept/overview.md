# Product Overview (Operational)

## One-liner

AI Video Effects Studio lets a user upload a video, apply an AI-powered effect, preview the result, and export it (watermarked for free users; clean for paid users).

## Core loop (MVP)

1. Browse effects
2. Choose an effect
3. Upload video
4. Process video (async job status)
5. Preview result
6. Export/share (upgrade gates)

## Monetization primitives

- Watermark / animated frames as a **freemium gate**
- Packages / tiers / subscriptions

## Hard constraints (from seed)

- Multi-tenant user isolation (USER_PRIVATE data is per-user)
- AI processing is external (we store metadata and job status, not model internals)
- Video files stored in object storage (DB stores file metadata + paths)
- Processed videos retained for a short period before cleanup (seed mentions 2 days)

## Seed reference

See `docs/init/project_concept.md` for the full narrative concept.

