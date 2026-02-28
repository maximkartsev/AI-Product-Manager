# AWS E2E Testing Governance (Mandatory)

This folder defines the required process for all phase-level real AWS end-to-end tests.

## Mandatory rules

- Every phase must complete unit, feature, blackbox/flow, integration, and AWS E2E tests.
- AWS E2E tests must run on the real staging infrastructure.
- An E2E plan file must be written and approved before execution.
- A result file must be written after execution with real observed metrics and pass/fail conclusion.
- Cost gate:
  - up to `$50` per run: pre-approved
  - above `$50`: explicit user approval required before execution
- If a run fails, record the failure and continue improving/retesting until pass.

## Folder structure

- `environment/staging.md` - baseline staging infrastructure indicators and validation checklist.
- `templates/e2e-plan.template.md` - required pre-execution test plan template.
- `templates/e2e-result.template.md` - required post-execution result template.
- `phases/` - per-phase plans and result logs.
