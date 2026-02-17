# Result Page (Processed Video)

## User goal

Preview the processed output and export/share.

## Key UI

- Video preview player
- Watermark controls (opacity, style selection)
- Download/export CTA
- Upgrade CTA (remove watermark / premium export)
- “Publish to gallery” action (if supported)

## Backend needs

- Read video result (status + output file reference)
- List watermark styles available (free/premium)
- Create export record and return download URL when ready

## Acceptance

- For free users, exports are watermarked
- Premium action triggers payment/upgrade flow
- Publishing is gated and auditable

