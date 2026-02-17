# Auth Modal (Sign-up / Login)

## User goal

Authenticate with minimal friction and resume the action they attempted.

## Key UI

- Fast sign-up/login (email or social)
- Clear benefits copy
- Return-to-action behavior after success

## Backend needs

- Registration and login endpoints
- Token auth (Sanctum)
- Consistent JSON errors for unauthenticated requests

### API contract (email/password)

- `POST /api/register`
  - **Body**: `{ name, email, password, c_password }`
  - **Response envelope**: `{ success: true, data: { token, name, tenant }, message?: string }`
  - **tenant**: `{ id, domain, db_pool }`
- `POST /api/login`
  - **Body**: `{ email, password }`
  - **Response envelope**: `{ success: true, data: { token, name, tenant? }, message?: string }`

### Frontend storage (contract)

- `localStorage.auth_token`: Bearer token used by `Authorization: Bearer <token>`
- `localStorage.tenant_domain`: tenant host (e.g. `alice1.localhost`) used for tenant routes like `/api/me`

### Return-to-action

- On success: store token + tenant domain, close the modal, and resume the user’s attempted action (at minimum: return to the same page without losing state).

## Acceptance

- Unauthenticated upload/export actions trigger auth
- After auth, user returns to the original flow

