# Upload & Processing Page

## User goal

Upload a video and see progress while the effect is applied.

## Key UI

- Upload widget (file picker)
- Processing progress indicator
- Clear error states (validation, processing failure)

## Backend needs

- Create a `File` record for the upload (metadata)
- Create a `Video` processing job (status transitions)
- Polling endpoint or status fetch (read video by id)

## Acceptance

- Upload succeeds and creates processing job
- UI can poll status until completed/failed
- Failures return actionable error messages

