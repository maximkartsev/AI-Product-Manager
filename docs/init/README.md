# docs/init — Seed Inputs (Project Entrypoint)

This folder contains the **seed product docs** that start a project.

For new projects, the workflow is:

1. The user writes/provides the seed files (copy into this folder):
   - `docs/init/project_concept.md`
   - `docs/init/project_pages.md`
   - `docs/init/user-journeys.md`
   - `docs/init/database.md`
2. The user copies the instructor boilerplate into the repo (excluding `docs/` and `aios/`).
3. The AI OS derives and maintains the implementation-ready docs under `docs/ai-supported/*`.

## Rules

- Treat `docs/init/*` as **inputs**, not the daily “working set”.
- Keep implementation details and evolving decisions in:
  - `docs/ai-supported/*` (operational docs)
  - `docs/ai-supported/adr/*` (architecture decisions)
  - `docs/ai-supported/cycles/*` (cycle-by-cycle records)

See `aios/v1/` for the full development system.

