# Landing Page

## User goal

Discover the product and choose an effect to try quickly.

## Key UI

- Hero preview (vertical video)
- Popular effects carousel (click-through to Effect Detail)
- Primary CTA: “Do the Same” (opens Auth modal; upload is gated by auth)

## Backend needs

- Public effects catalog (GLOBAL, central DB)
- Public endpoint to list active effects (no auth, no tenant init required)

### API contract

- `GET /api/effects`
  - **Auth**: none (public)
  - **Response envelope**: `{ success: true, data: Effect[], message?: string }`
  - **Effect fields (minimum)**:
    - `id`, `name`, `slug`, `description?`, `thumbnail_url?`, `preview_video_url?`, `is_premium`, `is_active`

## Acceptance

- Page loads with effects list (Loading → Success/Empty/Error states are explicit)
- Clicking an effect navigates to `/effects/[slug]`
- Upload/processing actions require auth (Auth modal is the gate)

