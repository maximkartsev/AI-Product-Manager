# AWS E2E Plan

## Metadata

- Phase: phase01
- Date: 2026-03-01
- Owner: AI agent
- Linked implementation plan file: `.cursor/plans/phase1_scenario_executor_f250f1d6.plan.md`
- Linked issue/task ids: `phase1-test-suite`, `phase1-staging-smoke`

## Objective and success criteria

- Actual goal under test: validate Scenario Executor on real staging for both a smoke run and a fault-injection run.
- Explicit pass criteria:
  - Smoke run starts from `/admin/studio/load-test-runs/{id}/start` and reaches `completed` with non-zero `submitted_count`.
  - Status polling endpoint returns live queue/completion counts and p95 metrics.
  - Fault scenario (`fault_enabled=true`, `fault_method=fis`) records `fis_experiment_arn` and `target_instance_ids`.
  - Cancel flow remains idempotent and runner stop metadata is preserved.
- Explicit fail criteria:
  - Run cannot start due ECS/FIS/ASG prerequisites.
  - Run transitions to `failed` without actionable diagnostics.
  - Fault scenario does not produce FIS evidence in `metrics_json` or status endpoint payload.

## Scope

- Included modules/contracts:
  - `POST /api/admin/studio/load-test-runs/{id}/start`
  - `POST /api/admin/studio/load-test-runs/{id}/cancel`
  - `GET /api/admin/studio/load-test-runs/{id}/status`
  - `studio:run-load-test` runner path launched via ECS task
  - Stage-level FIS injection + ASG target selection
- Excluded items:
  - Iteration1 benchmark matrix and quality evaluation flows
  - Non-staging local inline validation
- Required prerequisites completed:
  - Phase1 migrations + backend/frontend changes merged to staging artifact
  - At least one `execution_environment.kind=test_asg` mapped to an active ASG
  - At least one FIS experiment template available for stage config
  - Staging effect revision + input fixture file prepared

## Real staging indicators (captured before run)

- Link to baseline: `docs/testing/e2e/aws/environment/staging.md`
- ECS/ASG/RDS/Redis/S3 indicators captured at: `2026-03-01T03:20:02.9184158+00:00`
  - ECS cluster `bp`: `ACTIVE`, `runningTasksCount=2`, `activeServicesCount=2`
  - ECS services:
    - `bp-backend`: `desired=1`, `running=1`, task def `bp-backend:7`
    - `bp-frontend`: `desired=1`, `running=1`, task def `bp-frontend:6`
  - Backend service awsvpc: subnets `subnet-0253653a6a621afe6`, `subnet-0f868f719bcb2c10b`; SG `sg-06f66534f1e4bed15`
  - Auto Scaling Groups: none detected (`[]`)
  - RDS `bp-data-databaseb269d8bb-ihwoymhnb69z`: `available`
  - Redis `bp-re-1bxkoxlk6haqh`: `available`
  - S3 buckets: `bp-access-logs-108993613908`, `bp-logs-108993613908`, `bp-media-108993613908`, `bp-models-108993613908`
  - FIS templates: none detected (`[]`)
- CloudWatch alarm baseline captured at: `2026-03-01T03:20:02.9184158+00:00`
  - `ALARM`: `TargetTracking-service/bp/bp-backend-AlarmLow-*`, `TargetTracking-service/bp/bp-frontend-AlarmLow-*`
  - `OK`: `p1-backend-unhealthy-hosts`, `p1-alb-5xx-critical`, `p1-rds-cpu-critical`, `p1-rds-storage-low`, `p2-alb-5xx-warning`, `p2-rds-cpu-warning`

## Test data and scenario

- Input dataset id/version: `phase01-scenario-executor-v1`
- Expected output artifacts:
  - `load_test_runs.status` transitions and timestamps
  - `load_test_runs.metrics_json.runner.launch.task_arn`
  - `load_test_runs.metrics_json.stages[].fault.{fis_experiment_arn,target_instance_ids}`
  - Status endpoint payload (`submitted/queued/leased/completed/failed` + p95 metrics + `fault_events`)
- Correlation ids/idempotency keys strategy:
  - Dispatch ids: `studio_load_test_<run_id>_<seq>_<uuid>`
  - Benchmark context id: `phase01-smoke-<timestamp>` and `phase01-fault-<timestamp>`

## Mandatory test steps

1. Integration test gate execution summary (must pass before E2E).
   - Run:
     - `php artisan test --filter 'LoadTestRunDispatchLinkTest|StudioExecutorSecretTest|ScenarioSchedulerTest|LoadTestRunAggregationTest|StudioLoadTestSubmissionTest|StudioLoadTestRunStartTest|StudioLoadTestRunStatusTest|FaultInjectionServiceTest'`
2. Deploy/enable test configuration.
   - Deploy latest infrastructure + backend/frontend containers.
   - Verify `STUDIO_LOAD_TEST_RUNNER_*` runtime values are populated from stack.
   - Ensure at least one FIS template exists and is referenced by scenario stage config.
3. Execute smoke scenario on real staging.
   - Create run from scenario with no fault stage, start in ECS mode, poll `/status` until terminal state.
4. Execute fault scenario on real staging.
   - Create run with one stage where `fault_enabled=true` and `fault_method=fis`.
   - Start run and verify fault evidence in status payload / run metrics.
5. Validate outputs using observable contracts (APIs/events/artifacts/metrics).
   - Confirm run state machine, metrics, and cancel idempotency where applicable.

## Manual and approval gates

- Manual steps requiring user/operator action:
  - Confirm staging fixtures (effect revision + input file + scenario IDs) are available.
  - Provide/approve FIS template to use in phase01 fault stage.
- Estimated run cost (USD): 10-30
- Above `$50`? (`yes` or `no`): no
- If yes, approval reference: n/a

## Evidence to collect

- API responses/events:
  - Start, status, and cancel endpoint payloads for smoke + fault runs.
- CloudWatch links/screenshots:
  - ECS cluster `bp` task events for runner task definition.
  - Relevant alarm state transitions during test window.
- Artifact locations:
  - `docs/testing/e2e/aws/phases/phase01/results/2026-03-01-phase01-scenario-executor-e2e.md`
- Logs:
  - `/ecs/bp-backend` and `/ecs/bp-load-test-runner` streams
  - Action log events `load_test_runner_ecs_launched`, `load_test_run_cancelled`, `load_test_runner_ecs_fallback` (if triggered)

## Rollback/safety

- Rollback plan if run destabilizes staging:
  - Cancel active run via `/cancel`.
  - Stop runner ECS task if still active.
  - Disable fault stage and rerun smoke only.
- Stop conditions:
  - Sustained backend error rate above normal baseline.
  - Unhealthy backend target count >= 1 for more than 5 minutes.
  - Unexpected RDS/Redis degradation during scenario window.
