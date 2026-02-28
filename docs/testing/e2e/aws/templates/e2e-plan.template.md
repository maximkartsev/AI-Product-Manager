# AWS E2E Plan Template

> Create from this template before executing a phase E2E test.

## Metadata

- Phase:
- Date:
- Owner:
- Linked implementation plan file:
- Linked issue/task ids:

## Objective and success criteria

- Actual goal under test:
- Explicit pass criteria:
- Explicit fail criteria:

## Scope

- Included modules/contracts:
- Excluded items:
- Required prerequisites completed:

## Real staging indicators (captured before run)

- Link to baseline: `docs/testing/e2e/aws/environment/staging.md`
- ECS/ASG/RDS/Redis/S3 indicators captured at:
- CloudWatch alarm baseline captured at:

## Test data and scenario

- Input dataset id/version:
- Expected output artifacts:
- Correlation ids/idempotency keys strategy:

## Mandatory test steps

1. Integration test gate execution summary (must pass before E2E).
2. Deploy/enable test configuration.
3. Execute end-to-end scenario on real staging.
4. Validate outputs using observable contracts (APIs/events/artifacts/metrics).
5. Validate economic/stability indicators and operator-action logs.

## Manual and approval gates

- Manual steps requiring user/operator action:
- Estimated run cost (USD):
- Above `$50`? (`yes` or `no`):
- If yes, approval reference:

## Evidence to collect

- API responses/events:
- CloudWatch links/screenshots:
- Artifact locations:
- Logs:

## Rollback/safety

- Rollback plan if run destabilizes staging:
- Stop conditions:
