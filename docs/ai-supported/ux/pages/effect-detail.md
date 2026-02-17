# Effect Detail Page

## User goal

Understand an effect and start an upload to apply it.

## Key UI

- Effect preview (looping video or before/after)
- Effect name + short description
- Primary CTA: “Upload Video”
- Related effects (optional)

## Backend needs

- Read effect by `slug` (preferred) or `id`
- Return effect metadata (name, description, preview URL, premium flag)

### Route

- `/effects/[slug]`

### API contract

- `GET /api/effects/{slugOrId}`
  - **Auth**: none (public)
  - **Response envelope**: `{ success: true, data: Effect, message?: string }`
  - **Not found**: `success: false` with a 404

## Acceptance

- Effect loads by URL
- Premium effects are clearly labeled
- Upload CTA triggers auth if unauthenticated

