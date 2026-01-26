# Journey: Subscription Upgrade

## Summary

A user attempts a premium action (remove watermark / premium export), pays, gains access, and exports a clean video.

## Entities

- Tier/Package (GLOBAL)
- Purchase (USER_PRIVATE)
- Payment (USER_PRIVATE)
- Subscription (USER_PRIVATE)
- Export (USER_PRIVATE)

## Backend contracts

- Premium action triggers upgrade flow
- Webhooks update purchase/payment/subscription states
- Export checks entitlement (subscription/credits) before producing clean output

## Seed reference

See `docs/init/user-journeys.md` (User Subscription Upgrade).

