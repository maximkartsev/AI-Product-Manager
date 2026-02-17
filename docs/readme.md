# Documentation Guide (How to work with docs)

This repo uses a **two-layer documentation system** designed for AI-assisted, repeatable development:

- **Seed docs (entrypoint inputs)**: `docs/init/*` (provided/maintained by the user)
- **Operational docs (implementation-ready)**: `docs/ai-supported/*` (derived and maintained by the AI agent)

Additionally, the repo includes a reusable cross-project **AI Operating System**:

- **AI OS v1**: `aios/v1/*`

## 1) The three entrypoint files (seed docs)

These are required in every project and must exist in `docs/init/`:

- `docs/init/project_concept.md`
- `docs/init/project_pages.md`
- `docs/init/user-journeys.md`
- `docs/init/database.md`

**How to use them**

- Treat them as **stable inputs** (product intent + UX intent + journeys).
- Do not bloat them with implementation details (routes, migrations, code snippets).
- When the product direction changes, update the seed docs and then regenerate/adjust the operational docs via the AI workflow.

See also: `docs/init/README.md`

## 2) AI OS (cross-project development system)

The AI OS defines the “how we build” rules and prompts.
It is intended to be portable across projects.

- Entry: `aios/v1/README.md`
- Philosophy/constitution: `aios/v1/level1_philosophy.md`
- Doc taxonomy rules: `aios/v1/level1_doc_taxonomy.md`
- Prompt catalog (Level 2): `aios/v1/level2_prompts/index.md`
- Porting guide: `aios/v1/PORTING.md`

## 3) Operational project docs (derived from seeds)

This folder is what AI agents should primarily read during implementation:

- `docs/ai-supported/README.md` (index + “active AI OS version”)
- `docs/ai-supported/concept/*` (small, implementable concept chunks)
- `docs/ai-supported/ux/pages/*` and `docs/ai-supported/ux/journeys/*`
- `docs/ai-supported/data/*`
- `docs/ai-supported/adr/*`
- `docs/ai-supported/requirements/*` and `docs/ai-supported/cycles/*`

## 4) Daily workflow (docs-first)

For any new change:

1. Update/confirm seed intent (`docs/init/*`) only if the product intent changed.
2. Update operational docs first (`docs/ai-supported/*`).
3. Apply schema changes (migrations), then generate code (generators), then implement custom logic.
4. Record the work in a CycleRecord (`docs/ai-supported/cycles/*`).

## 5) Checks (production-ready local gates)

These commands are the local enforcement gates (WSL/Laradock):

- `make preflight` — docs checks + backend migrate/tests + frontend lint/build
- `make release-check` — validates latest CycleRecord is complete and marked ready

## 6) Historical docs

Historical docs are intentionally removed to keep the working set small and unambiguous.

