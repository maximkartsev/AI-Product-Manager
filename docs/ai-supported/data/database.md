# Database (How to use the schema docs)

## Source of truth

The detailed schema is maintained in:

- `docs/init/database.md`

## How to use it in implementation cycles

When implementing a change:

1. Identify the entities involved and their **Scope** (GLOBAL / USER_PRIVATE / USER_SHARED).
2. Identify read/write hot paths (polling, job status updates, exports).
3. Add the minimal indexes needed for the hot paths.
4. Use generators to create code artifacts after migrations.

## Ownership relevance

USER_PRIVATE entities must be user-owned and enforce isolation (a user cannot read/write another user’s rows).

Baseline implementation:

- `backend/config/ownership.php`
- `App\Models\Concerns\UserOwned`

