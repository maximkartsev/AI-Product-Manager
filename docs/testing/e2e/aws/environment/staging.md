# Staging Environment Baseline (Required Before Any AWS E2E Run)

Fill this file before executing any phase E2E test. Keep values current.

## Environment identity

- AWS account alias/id: `108993613908`
- Region: `us-east-1`
- VPC id: `vpc-0794ba4d156944c21`
- Subnets used for test workloads: `subnet-0253653a6a621afe6`, `subnet-0f868f719bcb2c10b`
- Security groups used for test workloads:
  - Backend ECS: `sg-06f66534f1e4bed15`
  - Frontend ECS: `sg-0ab4d99f9479bda90`
  - RDS: `sg-00194044f2f9bdd51`
  - Redis: `sg-0ec663b2ba4d3c5a3`

## Core runtime indicators

- ECS cluster/service names and desired/running counts:
  - Cluster `bp`: active services `2`, running tasks `2`
  - Service `bp-backend`: desired/running `1/1`
  - Service `bp-frontend`: desired/running `1/1`
- Auto Scaling Group names and desired/min/max counts:
  - None detected (`aws autoscaling describe-auto-scaling-groups` returned empty list)
- EC2 instance type mix (including GPU nodes if any):
  - n/a from ASG baseline (no ASGs present)
- ElastiCache/Redis endpoint and health:
  - `bp-re-1bxkoxlk6haqh.m78ivv.0001.use1.cache.amazonaws.com:6379` (`available`)
- RDS instance/cluster identifiers and health:
  - `bp-data-databaseb269d8bb-ihwoymhnb69z` (`mariadb`, `db.t4g.medium`, `available`)
- S3 bucket names used in tests and access status:
  - `bp-access-logs-108993613908` (present)
  - `bp-logs-108993613908` (present)
  - `bp-media-108993613908` (present)
  - `bp-models-108993613908` (present)

## Observability indicators

- CloudWatch dashboard links: TODO
- CloudWatch alarm baselines (state before run):
  - `ALARM`: `TargetTracking-service/bp/bp-backend-AlarmLow-*`, `TargetTracking-service/bp/bp-frontend-AlarmLow-*`
  - `OK`: `p1-backend-unhealthy-hosts`, `p1-alb-5xx-critical`, `p1-rds-cpu-critical`, `p1-rds-storage-low`, `p2-alb-5xx-warning`, `p2-rds-cpu-warning`
- Log group names relevant to test:
  - `/ecs/bp-backend`
  - `/ecs/bp-frontend`
  - `/ecs/bp-load-test-runner` (expected after phase01 infra deployment)
- Any TIG/Grafana endpoints used for non-sensitive metrics: TODO

## Budget and cost guardrails

- Expected E2E run cost estimate (USD): `10-30`
- Is estimate `<= $50`? (`yes` or `no`): `yes`
- If `no`, explicit user approval reference: `n/a`

## Test data baseline

- Fixture dataset id/version: `phase01-scenario-executor-v1`
- Fixture object checksums or hashes: TODO
- Provider/model versions expected in this run: AWS ComfyUI fleet workloads (exact revision IDs selected at execution time)

## Readiness checklist

- [x] Runtime indicators captured within the last 24 hours
- [x] Cost estimate reviewed
- [x] Approval recorded when estimated cost is above `$50`
- [ ] Fixture set validated and immutable for this run
