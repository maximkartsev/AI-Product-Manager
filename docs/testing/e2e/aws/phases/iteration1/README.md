# Iteration 1 Staging E2E Suite

This suite validates the Iteration 1 economics + provider switching flow on real staging:

1. scenario load/fault execution
2. benchmark matrix smoke run
3. quality and economics winner selection
4. routing apply + rollback safety

## Prerequisites

- Staging infra healthy and baseline captured in `docs/testing/e2e/aws/environment/staging.md`.
- Backend migrations applied (including benchmark, quality, routing, and action logs tables).
- `STUDIO_EXECUTOR_SECRET` set for executor endpoint.
- At least one:
  - published effect + effect revision
  - active `test_asg` execution environment
  - valid input file

## Scenario Run (including FIS fault stage)

1. Create a load test run via `POST /api/admin/studio/load-test-runs`.
2. Start execution via `POST /api/admin/studio/load-test-runs/{id}/start` (default ECS mode with inline fallback).
3. Optional cancel path via `POST /api/admin/studio/load-test-runs/{id}/cancel`.
4. Verify run metrics and aggregation via `GET /api/admin/studio/load-test-runs/{id}`.

Expected:

- `metrics_json.stages[*]` includes submitted dispatches and fault status.
- `metrics_json.aggregation` includes latency/failure/cost metrics.
- action logs include launch/fallback/cancel events as appropriate.

## Benchmark Smoke Run

1. Submit benchmark matrix via `POST /api/admin/studio/benchmark-matrix-runs`.
2. Inspect result via `GET /api/admin/studio/benchmark-matrix-runs/{id}`.
3. Confirm variant dispatch artifact rows in `run_artifacts` (`artifact_type=benchmark_variant_dispatches`).

Expected:

- each variant creates a matrix item with dispatch IDs.
- benchmark run has `benchmark_context_id` and item metrics for economics/quality.

## Quality + Money HUD Selection

1. Create quality evaluations for benchmark items via `POST /api/admin/studio/quality-evaluations`.
2. Load economics HUD via `GET /api/admin/studio/economics/money-hud?benchmark_matrix_run_id=<id>`.
3. Confirm winner row + recommendation list.

Expected:

- quality evaluation rows persist with composite scores.
- Money HUD exposes `winner`, `rows`, and actionable `recommendations`.

## Routing Apply + Rollback

1. Inspect current routing via `GET /api/admin/studio/routing/effect-revisions/{effectRevisionId}`.
2. Apply winner variant via `POST /api/admin/studio/routing/apply`.
3. Roll back via `POST /api/admin/studio/routing/rollback`.

Expected:

- active binding changes atomically per apply/rollback call.
- public submissions follow active binding provider/workflow/environment.

## Observability and Anomaly Guidance

- Pull action logs: `GET /api/admin/studio/action-logs`.
- Sink mapping: `GET /api/admin/studio/observability/sinks`.
- Trigger anomaly scan:
  - API: `POST /api/admin/studio/action-logs/scan-anomalies`
  - CLI: `php artisan studio:scan-action-log-anomalies --lookback-minutes=30`

Expected:

- warn/critical logs include `economic_impact_json` and `operator_action_json`.
- anomaly detector emits critical action log when patterns indicate elevated risk.

