# Journey: Freemium Video Effect Application

## Summary

User chooses an effect, uploads a video, processes it, previews the result, and exports a watermarked version.

## Entities

- Effect (GLOBAL)
- File (USER_PRIVATE)
- Video (USER_PRIVATE)
- Watermark (USER_PRIVATE or GLOBAL, depending on design)
- Export (USER_PRIVATE)

## Backend contracts

- Effects are browseable without auth.
- Upload + processing requires auth.
- Ownership enforcement on all USER_PRIVATE entities is mandatory.

## Seed reference

See `docs/init/user-journeys.md` (Freemium Video Effect Application).

