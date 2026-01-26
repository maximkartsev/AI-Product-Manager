# Public Gallery (Explore)

## User goal

Browse public examples and jump into using the same effect.

## Key UI

- Public feed/grid
- Filter/search (optional)
- CTA per item: “Use this effect”

## Backend needs

- Read-only public endpoint returning published items
- Global projection/index strategy (avoid expensive fan-out for the public path)

## Acceptance

- Public gallery does not leak private data
- Clicking “Use this effect” routes to effect detail and then upload flow

