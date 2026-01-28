---
requirement_id: 2026-01-27_landing-effects-integration
status: draft
owner: user
---

# Requirement Card: 2026-01-27_landing-effects-integration

## Goal

Connect the Landing page to real backend auth and the Effects catalog, and add an Effect Detail route that loads by slug.

## Users and job-to-be-done

- Primary user: Visitor / creator trying the product for the first time
- Job: Browse available effects, understand one, then authenticate to continue into the creation flow
- Success moment: User can open an effect detail page from Landing and successfully sign up/sign in (token + tenant captured)

## Scope (MVP)

- In scope:
  - Landing loads Popular Effects from `GET /api/effects` (public)
  - Clicking an effect navigates to `/effects/[slug]`
  - Effect Detail loads effect by slug via `GET /api/effects/{slugOrId}` (public)
  - Auth modal performs real `POST /api/register` and `POST /api/login`
  - Frontend stores `auth_token` and `tenant_domain` and uses them for tenant routes
  - UI integration gate (Playwright) updated to use tenant domain for tenant endpoints
- Out of scope:
  - Upload/processing flow implementation
  - Public Gallery data integration
  - Payments/subscriptions

## Acceptance criteria (testable)

- [ ] Landing shows explicit Loading → Success/Empty/Error states for effects
- [ ] Landing effect click navigates to `/effects/[slug]`
- [ ] Effect Detail shows explicit Loading → Success/Error/Not-found states
- [ ] `POST /api/register` and `POST /api/login` succeed from the Auth modal and store `auth_token` + `tenant_domain`
- [ ] `make ui-check` passes (Playwright e2e)

## Data model impact

- Tables affected:
  - `effects` (GLOBAL, **central** DB) — new
- New fields/indexes:
  - `slug` unique, `is_active` indexed, `deleted_at` (soft delete)
- Ownership rules:
  - Effects are GLOBAL catalog items; no user ownership. They must be readable without tenant initialization.

## API impact

- Endpoints:
  - `GET /api/effects` (public)
  - `GET /api/effects/{slugOrId}` (public)
  - Existing: `POST /api/register`, `POST /api/login`
- Validation:
  - Technical (structure: required/type/limits/existence):
    - `Effect::getRules()` present and aligned with DB types (for `validation:check`)
    - Auth endpoints validate email/password fields as implemented in backend controllers
  - Business (allowed action: ownership/RBAC/quotas/state rules):
    - Effects listing returns only active effects (and not soft-deleted)
  - AI safety (future; prompts/outputs if applicable):
    - N/A
- Errors (expected 4xx/5xx and shapes):
  - 401 for unauthenticated tenant endpoints (e.g. `/api/me`)
  - 404 for unknown effect slug/id
  - Standard JSON envelope: `success`, `message`, optional `data`

## UX impact

- Pages/components:
  - `frontend/src/app/_components/landing/LandingHome.tsx`
  - `frontend/src/app/_components/landing/AuthModal.tsx`
  - `frontend/src/app/effects/[slug]/page.tsx`
- Click paths:
  - Landing → effect card → Effect Detail → Upload CTA → Auth modal (if unauthenticated)
- Empty states:
  - No effects available (Landing)
  - Effect not found (Effect Detail)

## Risks / open questions

- Risk: Tenant routes require tenant domain; ensure client chooses the correct base URL after auth.
- Open question: Do we want a dedicated “tenant selector” UX later, or is tenant determined only by registration/login response?

