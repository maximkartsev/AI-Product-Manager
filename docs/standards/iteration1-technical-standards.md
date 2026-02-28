# Iteration 1 Technical Standards

## Scope and authority

- This document defines cross-phase technical standards for Iteration 1.
- `.cursor/plans/` files are the authoritative implementation specification.
- `docs/plans/` files are reference documents; if they conflict, `.cursor/plans/` wins.

## Canonical module IDs

- Canonical product modules use `M1..M10`:
  - `M1` Video Intake
  - `M2` Content Analysis
  - `M3` Provider Registry
  - `M4` Prompt Generation
  - `M5` Orchestrator
  - `M6` Execution Engine
  - `M7` Quality Evaluation
  - `M8` Economic Engine
  - `M9` Notification and Approval
  - `M10` Gallery and Publishing
- Cross-cutting infrastructure (event bus, observability, hardening) may be labeled as cross-cutting and should not reuse canonical module IDs incorrectly.

## Contract versioning

- Event and DTO naming format: `{EventName}.v1`.
- Payloads must include explicit `schema_version=v1`.
- Schema changes that break compatibility require a new version (`v2`), not silent mutation.

## Idempotency standard

- Canonical key format:
  - `{module}:{subject_id}:{operation}:{version}`
- Idempotency is mandatory for:
  - event publishing/consumption side effects
  - retry-prone APIs and jobs
  - approval/publish actions
  - routing apply/rollback actions

## Stream naming and usage

- Business events stream: `studio:business`.
- Telemetry stream namespace: `studio:telemetry`.
- Iteration 1 does not require mandatory producers on `studio:telemetry`.
- Phase 5 action logs are DB-first (`action_log_events`) and use DB as the source of truth.

## No-legacy policy

- Implement only the target architecture.
- Do not introduce dual-path behavior, compatibility shims, or deprecated fallback flows.
- Any migration logic must be one-way, explicit, and time-bounded.
- New DB columns should be nullable unless hard-required; existing records remain valid historical data and do not require synthetic backfills.

## Testing gates (mandatory for every phase)

1. Unit tests
2. Feature tests
3. Blackbox/flow tests
4. Integration tests
5. AWS E2E tests on real staging infrastructure

## AWS E2E governance

Required baseline/artifacts:
- `docs/testing/e2e/aws/environment/staging.md`
- Plan template: `docs/testing/e2e/aws/templates/e2e-plan.template.md`
- Result template: `docs/testing/e2e/aws/templates/e2e-result.template.md`

Required phase folder structure:
- `docs/testing/e2e/aws/phases/phaseXX/plans/`
- `docs/testing/e2e/aws/phases/phaseXX/results/`

Required pre-run indicators:
- account and region
- network and compute readiness
- data fixture identifiers/checksums
- service and queue health
- alarm/dashboard state

Cost and approval gate:
- Auto-approve up to `$50` per run.
- Above `$50` requires explicit user approval before execution.

Manual user involvement checkpoints:
1. Confirm staging infrastructure readiness.
2. Approve tests above cost gate.
3. Approve manual provider/workflow/infra changes that cannot be automated.

Failure policy:
- Record real infrastructure results in phase result files.
- If tests fail, implement corrections and rerun until pass, documenting each rerun.

## Observability standards

- Warn/critical action logs must include:
  - quantified impact (economic or stability)
  - operator actions/runbook-style instructions
- Sensitive economics must remain admin-only.
- Non-admin sinks must use explicit redaction/allowlist policy.

## Economic accounting standards

- Per-dispatch economics must be deterministic and reconcilable.
- User budget views must exclude non-user contexts.
- Required `budget_context` values when relevant:
  - `user_run`
  - `system_test`
  - `benchmark`
