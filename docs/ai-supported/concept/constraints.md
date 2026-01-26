# Constraints & Assumptions

## Security

- USER_PRIVATE data must be isolated by user (ownership enforcement).
- Public surfaces must not leak private records.

## Storage

- Original and processed files are separate entities.
- Files are stored in an object store (S3-compatible); DB holds references and metadata.
- Free outputs may be deleted after a retention window (seed mentions 2 days).

## AI processing

- Processing is performed by external services.
- Persist only what is required for UX, billing, and support (status, timings, parameters, outputs).

## Payments

- Use a third-party provider (Stripe/Paddle).
- Store transaction records, not sensitive payment details.

