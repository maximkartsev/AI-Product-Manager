# AWS E2E Plan

## Metadata

- Phase: iteration1
- Date: 2026-02-28
- Owner: AI agent
- Linked implementation plan file: `.cursor/plans/iteration1-economics-provider-switching_acb675c7.plan.md`
- Linked issue/task ids: `m3-scenario-executor`, `variants-registry`, `benchmark-matrix`, `quality-eval-gemini`, `economic-engine-hud`, `routing-apply-rollback`, `observability-action-logs`, `e2e-staging-suite`

## Objective and success criteria

- Actual goal under test: verify end-to-end benchmark execution across variants, quality evaluation, money HUD economics, routing apply/rollback, and action logs on real staging.
- Explicit pass criteria:
  - Scenario run submits dispatches and aggregates run metrics.
  - Benchmark matrix run creates per-variant run items and dispatch artifacts.
  - Quality evaluations are generated for benchmark outputs.
  - Money HUD endpoint returns winner + recommendations for benchmark run.
  - Routing apply and rollback endpoints update active binding deterministically.
  - Action logs include operator instructions for warn/critical events.
- Explicit fail criteria:
  - Any required module endpoint returns non-2xx unexpectedly.
  - Run artifacts, quality evaluations, or routing bindings are missing.
  - Benchmark run has no measurable rows in Money HUD.

## Scope

- Included modules/contracts:
  - Scenario executor (`/admin/studio/load-test-runs/*`, `studio:run-load-test`)
  - Variant registry + benchmark matrix (`/admin/studio/variant-registry/*`, `/admin/studio/benchmark-matrix-runs*`)
  - Quality evaluation (`/admin/studio/quality-evaluations*`)
  - Economics HUD (`/admin/studio/economics/money-hud`)
  - Routing apply/rollback (`/admin/studio/routing/*`)
  - Action logs + observability mapping (`/admin/studio/action-logs`, `/admin/studio/observability/sinks`)
- Excluded items:
  - Iteration 2+ modules (publishing calendar, autopilot, marketplace).
  - Non-staging manual social publishing flows.
- Required prerequisites completed:
  - Database migrations for iteration1 module tables applied.
  - At least one active test/staging execution environment exists.
  - At least one effect revision and input fixture file exist.

## Real staging indicators (captured before run)

- Link to baseline: `docs/testing/e2e/aws/environment/staging.md`
- ECS/ASG/RDS/Redis/S3 indicators captured at: TODO before run
- CloudWatch alarm baseline captured at: TODO before run

## Test data and scenario

- Input dataset id/version: `iteration1-golden-v1`
- Expected output artifacts:
  - `run_artifacts` rows with `artifact_type=benchmark_variant_dispatches`
  - `benchmark_matrix_run_items` rows for each variant
  - `quality_evaluations` rows for benchmark context
- Correlation ids/idempotency keys strategy:
  - Scenario dispatches: `studio_load_test_<run>_<seq>_<uuid>`
  - Benchmark context ids: `bm_<uuid>`
  - Variant IDs: `er:<id>:wf:<id>:env:<id>:stage:<stage>:provider:<name>:exp:<id>`

## Mandatory test steps

1. Integration test gate execution summary (must pass before E2E):
   - `php artisan test tests/Unit/LoadTesting/LoadTestStageRatePlannerTest.php tests/Unit/LoadTesting/LoadTestMetricsAggregatorTest.php tests/Unit/Variants/VariantRegistryServiceTest.php tests/Unit/Quality/GeminiQualityEvaluationProviderTest.php`
2. Deploy/enable test configuration:
   - Set `STUDIO_EXECUTOR_SECRET`.
   - Set `STUDIO_LOAD_TEST_RUNNER_*` env vars or run in inline fallback mode.
3. Execute end-to-end scenario on real staging:
   - Create/load-test run and start it.
   - Execute benchmark matrix run.
   - Execute quality evaluations for benchmark outputs.
4. Validate outputs using observable contracts (APIs/events/artifacts/metrics):
   - Validate run status + metrics in load test and benchmark tables.
   - Validate artifact rows and quality evaluation rows.
5. Validate economic/stability indicators and operator-action logs:
   - Query Money HUD for benchmark run.
   - Query action logs and ensure operator instructions are present.
   - Apply routing binding to winner variant and rollback once.

## Manual and approval gates

- Manual steps requiring user/operator action:
  - Confirm staging infra readiness.
  - Supply benchmark fixture input file and effect revision IDs.
- Estimated run cost (USD): 20-45
- Above `$50`? (`yes` or `no`): no
- If yes, approval reference: n/a

## Evidence to collect

- API responses/events:
  - JSON payloads from load-test, benchmark, quality-eval, money-hud, routing endpoints.
- CloudWatch links/screenshots:
  - ECS task run (if ECS mode), relevant alarms.
- Artifact locations:
  - `run_artifacts` entries for benchmark matrix items.
- Logs:
  - `action_logs` rows for fallback/cancel/routing/quality fallback events.

## Rollback/safety

- Rollback plan if run destabilizes staging:
  - Cancel active load test run.
  - Roll back routing binding to previous variant.
  - Revert temporary staging config changes.
- Stop conditions:
  - Elevated failure rate > 20% sustained for >10 minutes.
  - Critical infrastructure alarms triggered.

