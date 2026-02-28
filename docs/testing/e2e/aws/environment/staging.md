# Staging Environment Baseline (Required Before Any AWS E2E Run)

Fill this file before executing any phase E2E test. Keep values current.

## Environment identity

- AWS account alias/id:
- Region:
- VPC id:
- Subnets used for test workloads:
- Security groups used for test workloads:

## Core runtime indicators

- ECS cluster/service names and desired/running counts:
- Auto Scaling Group names and desired/min/max counts:
- EC2 instance type mix (including GPU nodes if any):
- ElastiCache/Redis endpoint and health:
- RDS instance/cluster identifiers and health:
- S3 bucket names used in tests and access status:

## Observability indicators

- CloudWatch dashboard links:
- CloudWatch alarm baselines (state before run):
- Log group names relevant to test:
- Any TIG/Grafana endpoints used for non-sensitive metrics:

## Budget and cost guardrails

- Expected E2E run cost estimate (USD):
- Is estimate `<= $50`? (`yes` or `no`):
- If `no`, explicit user approval reference:

## Test data baseline

- Fixture dataset id/version:
- Fixture object checksums or hashes:
- Provider/model versions expected in this run:

## Readiness checklist

- [ ] Runtime indicators captured within the last 24 hours
- [ ] Cost estimate reviewed
- [ ] Approval recorded when estimated cost is above `$50`
- [ ] Fixture set validated and immutable for this run
