# Journey: Subscription Upgrade

## Summary

A user attempts a premium action (remove watermark / premium export), pays, gains access, and exports a clean video.

## Entities

- Tier/Package (GLOBAL)
- Purchase (CENTRAL)
- Payment (CENTRAL)
- Subscription (CENTRAL)
- TokenWallet/TokenTransaction (TENANT)
- Export (USER_PRIVATE)

## Backend contracts

- Premium action triggers upgrade flow
- Webhooks update purchase/payment/subscription states in the central DB
- On payment success, webhook credits tokens in the tenant DB (idempotent)
- Export checks entitlement (subscription/credits) before producing clean output

## Seed reference

See `docs/init/user-journeys.md` (User Subscription Upgrade).

