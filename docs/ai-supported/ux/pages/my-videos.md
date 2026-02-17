# My Videos (User Gallery)

## User goal

Browse, manage, and re-open previously processed videos.

## Key UI

- Grid/list of the user’s videos (status, thumbnail)
- Per-item actions: open, download, share, delete
- “Create new” shortcut

## Backend needs

- List videos for current user (ownership enforced)
- Read video details by id (ownership enforced)
- Delete video (soft delete) and related resources

## Acceptance

- User can only see their own videos
- Pagination + search are supported

