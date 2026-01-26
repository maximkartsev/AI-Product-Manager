# Publish Modal (Share to Public Gallery)

## User goal

Publish a processed video to the public gallery with title/tags.

## Key UI

- Title
- Tags (optional)
- Confirm publish
- Notice about watermark/public visibility

## Backend needs

- Create/update a `GalleryVideo` (or equivalent) record
- Enforce ownership and publishing constraints
- Update global public projection (if used)

## Acceptance

- User can only publish their own video
- Public gallery shows published item shortly after publish

