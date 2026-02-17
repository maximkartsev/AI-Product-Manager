# Error Handling Strategy

## Overview

Our API uses a layered error handling strategy that distinguishes between **user mistakes** (client errors) and **system problems** (server errors), ensuring users always receive clear, actionable messages while developers get the full diagnostic details they need.

## HTTP Status Codes

- **422 Unprocessable Entity** — Validation failures and business rule violations. These are "user mistakes": missing required fields, invalid formats, duplicate slugs, exceeding the 5-job concurrency limit, or insufficient token balance. The response body includes field-level error details so the frontend can display inline messages.

- **404 Not Found** — The requested resource does not exist. Used when an effect, video, category, or user cannot be found by the given ID or slug.

- **401 Unauthenticated** — Missing or expired bearer token. The global exception handler in `bootstrap/app.php` ensures this always returns JSON (never a redirect).

- **403 Forbidden** — The user is authenticated but not authorized. For example, trying to access another user's video or attempting admin actions without admin role.

- **500 Internal Server Error** — Unexpected system failures. The user sees a generic friendly message: *"Operation could not be completed. Please try again or contact support."*

## User-Facing Messages vs Internal Logs

Raw exception messages (stack traces, SQL errors, file paths) are **never exposed** to users. Instead:

- Every `catch` block logs the full exception (`$e->getMessage()`, `$e->getTraceAsString()`) via `\Log::error()` to `storage/logs/laravel.log`.
- The API returns a sanitized, user-friendly message.
- A global catch-all in `bootstrap/app.php` handles any unhandled exceptions on API routes, logging the full trace and returning a generic 500 response.

## User Mistakes vs System Problems

| Type | HTTP Code | Example | User sees |
|------|-----------|---------|-----------|
| Validation error | 422 | Missing effect name | `"The name field is required."` |
| Business rule | 422 | Duplicate slug | `"The slug has already been taken."` |
| Not found | 404 | Invalid effect ID | `"Effect not found."` |
| Server crash | 500 | Database connection lost | `"An unexpected error occurred."` |

This separation ensures users can fix their own mistakes (422) while system problems (500) are only visible to developers in the logs.
