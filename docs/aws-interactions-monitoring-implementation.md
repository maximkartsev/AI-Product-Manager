# AWS Interactions and Monitoring Implementation

## Scope and fixed decisions

- Monitoring strategy: hybrid. Keep CloudWatch only where strictly required, use TIG as primary observability plane.
- DevNode scope: AWS-managed DevNodes with explicit start/stop lifecycle automation and monitoring.
- Plan implementation target: deliver the three requested artifacts:
  - Full AWS interaction inventory (current plus planned)
  - Theory-to-AWS mapping by monitoring layers and operator loop
  - Product-graded phased implementation plan

---

## Deliverable 1: Full AWS interaction inventory

### 1) Provisioning interactions (CDK control plane)

| Domain | AWS services/APIs | Interaction | Current implementation |
|---|---|---|---|
| Networking | EC2 VPC, subnets, NAT, security groups, VPC endpoint | Create network topology and traffic boundaries | `NetworkStack` provisions VPC, public/private/isolated subnets, NAT, SGs, S3 gateway endpoint |
| Data layer | RDS, ElastiCache, S3, CloudFront, Secrets Manager, SSM, IAM | Provision durable storage, CDN, secrets, parameters, packer profile | `DataStack` provisions MariaDB, Redis, media/models/logs buckets, CloudFront, app and asset secrets, SSM parameters |
| Compute layer | ECS/Fargate, ALB, ECR (referenced), CloudWatch Logs, IAM | Provision backend/frontend services, listeners/routes, task roles, log groups | `ComputeStack` provisions ECS cluster/services, ALB target routing, autoscaling, task role policies for S3, SSM, CloudWatch metrics |
| Monitoring baseline | CloudWatch, SNS, Budgets | Provision dashboards, alarms, alert topic, budget alerts | `MonitoringStack` provisions P1/P2 alarms, dashboard widgets, SNS topic, optional budget notifications |
| GPU shared controls | SNS, Lambda, Auto Scaling | Provision scale-to-zero control path | `GpuSharedStack` provisions SNS topic and Lambda that sets ASG desired capacity to zero |
| GPU fleet per slug | EC2 Launch Templates, Auto Scaling, CloudWatch, SNS, Lambda, IAM, CloudWatch Logs | Provision Spot ASG with dual scaling (0→1 step, 1→N target tracking), plus queue-empty scale-to-zero alarm action | `GpuFleetStack` + `FleetAsg` provision worker Spot ASG, queue/backlog scaling policies, queue-empty alarm to shared scale-to-zero SNS/Lambda, worker IAM and log retention |
| CI/CD base | ECR | Provision image repositories | `CiCdStack` provisions backend and frontend ECR repositories |

### 2) Runtime interactions in application services (control/data plane)

| Runtime domain | AWS services/APIs | Interaction | Source |
|---|---|---|---|
| Media and artifact upload/download | S3 (via SDK and Laravel disks) | Presigned upload/download URLs, multipart create/complete/abort | `backend/app/Services/PresignedUrlService.php` |
| Workflow and asset reads | S3-compatible disks (`s3`, `comfyui_models`, `comfyui_logs`) | Read workflow JSON, read/write artifact/model/log objects | `backend/app/Services/WorkflowPayloadService.php`, `backend/config/filesystems.php` |
| Fleet desired config control | SSM `PutParameter` | Write `/bp/<stage>/fleets/<slug>/desired_config` | `backend/app/Services/ComfyUiFleetSsmService.php`, `backend/app/Http/Controllers/Admin/ComfyUiFleetsController.php` |
| Fleet active bundle pointer | SSM `PutParameter` | Write `/bp/<stage>/fleets/<slug>/active_bundle` | `ComfyUiFleetSsmService`, `ComfyUiFleetsController::activateBundle` |
| Worker autoscaling signal publishing | CloudWatch `PutMetricData` | Publish `ComfyUI/Workers` metrics used by scaling/alerts | `backend/routes/console.php` (`workers:publish-metrics`) |
| Worker registration guardrail (optional) | Auto Scaling `DescribeAutoScalingGroups` | Validate worker EC2 instance belongs to expected ASG | `backend/app/Http/Controllers/ComfyUiWorkerController.php` |
| Worker bootstrap on EC2 | SSM `GetParameter`, S3 `GetObject`, CloudWatch Logs `PutLogEvents` | Read fleet secret and bundle pointer, sync assets, stream logs | `infrastructure/lib/constructs/fleet-asg.ts` user-data and worker role permissions |

### 3) Runtime interactions in CI/operations automation

| Workflow/automation | AWS services/APIs | Interaction |
|---|---|---|
| `deploy-infrastructure.yml` | CloudFormation (via CDK), full stack APIs | `cdk diff/deploy` selected stacks |
| `deploy.yml` | ECR, ECS | Push images, force ECS service deployments, wait for service stability |
| `db-migrate.yml` and `db-seed.yml` | ECS, CloudWatch Logs | Run one-off Fargate tasks, inspect task/log outcomes |
| `provision-gpu-fleet.yml` | SSM, CloudFormation/CDK | Resolve desired config and deploy `gpu-shared` plus `gpu-fleet-<slug>` |
| `apply-gpu-fleet-config.yml` | SSM, CloudFormation/CDK | Reconcile fleet config updates against existing fleet stacks |
| `build-ami.yml` | EC2/Packer, SSM, Auto Scaling | Build base AMI, write AMI to SSM, optional ASG instance refresh |
| `bake-ami.yml` | EC2/Packer, SSM, Auto Scaling | Bake active bundle into AMI, publish SSM alias, optional ASG refresh |
| `apply-comfyui-bundle.yml` | SSM, S3 | Send SSM command to running instance, optional upload command output to S3 |
| `create-dev-gpu-instance.yml` | EC2, SSM | Resolve AMI/profile, create SG, run instance, expose endpoint |
| `infrastructure/dev-gpu/launch.sh` | EC2, SSM, IAM | Start/stop dev instance lifecycle, resolve AMI from SSM, attach profile and ingress |

### 4) Planned AWS-managed DevNode interactions (target contract)

| Capability | Primary AWS API path | App-side writeback | Monitoring signal |
|---|---|---|---|
| Start DevNode | `autoscaling:SetDesiredCapacity` (ASG mode) or `ec2:StartInstances` (pinned mode) | `dev_nodes.status` -> `starting` -> `ready`, endpoint fields updated | start latency, boot failures, readiness timeout |
| Stop DevNode | `autoscaling:SetDesiredCapacity` to `0` or `ec2:StopInstances` | `dev_nodes.status` -> `stopping` -> `stopped` | idle hours, stop success/failure |
| Restart DevNode | stop then start orchestration | restart lifecycle audit row | restart count, MTTR |
| Endpoint registration | SSM path or DynamoDB registry (recommended), then sync to `dev_nodes` | endpoint + heartbeat timestamps | endpoint drift, stale heartbeat |
| Health probing | `ec2:DescribeInstanceStatus`, optional SSM managed-node checks, app health endpoint probe | readiness + health status | unhealthy duration, flap count |
| Bundle/apply ops | `ssm:SendCommand` on running node | audit log row + node metadata | apply success rate, duration |
| Lifecycle audit and cost attribution | CloudWatch metrics + DB audit rows + tags | immutable lifecycle events | cost per node-hour, failed actions, operator actions |

Recommended identity boundaries:
- Backend control role: least privilege for `ec2:Start/Stop/Describe*`, `autoscaling:SetDesiredCapacity/Describe*`, `ssm:Get/Put/SendCommand` on scoped resources.
- Worker role: `ssm:GetParameter`, `s3:GetObject/ListBucket` scoped to models/bundles, CloudWatch log writes.
- CI roles: split by workflow class (deploy, AMI, fleet, dev instance).

---

## Deliverable 2: Theory-to-AWS mapping by operating logic

### Layer 1: Product analytics (GA4/GTM behavior layer)

Theory requirements mapped:
- Event-first analytics, not page-only analytics.
- Event taxonomy as a stable contract.
- Naming discipline (`snake_case`, explicit `started/completed/failed` states).
- Metadata is structured and privacy-safe (no prompts/outputs/PII).
- SPA route-change tracking and cross-domain continuity for checkout/auth.

AWS interaction mapping:
- No mandatory AWS dependency for core Layer 1 event collection.
- Optional AWS side channel: CloudFront/ALB logs for traffic baselining only (not replacement for GA funnels).

Ownership:
- Product + frontend own taxonomy and event governance.
- Platform supports optional log exports for coarse traffic diagnostics.

Current gaps:
- Need explicit event dictionary and versioning policy in-repo.
- Need ownership rule preventing ad-hoc event creation across frontend/backend.

### Layer 2: AI usage and cost analytics (economics layer)

Theory requirements mapped:
- Immutable request-level usage records.
- Required fields: `user_id`, `feature_name`, `model_name`, `input_tokens`, `cached_input_tokens`, `output_tokens`, `total_tokens`, `estimated_cost`, `request_duration_ms`, `success`, `error_type`, `created_at`.
- Cost formula per request:
  - `cost = (input_tokens/1000 * input_price) + (output_tokens/1000 * output_price)`
- Aggregation outputs: cost by user/feature/model/plan/day, tokens per minute, margin.

AWS interaction mapping:
- Durable storage on RDS for usage logs and aggregates.
- S3 for artifact lineage where needed.
- Metrics export into TIG (primary) and CloudWatch only for hard scaling dependencies.

Ownership:
- Backend + data own log schema and aggregation pipelines.
- Product/finance own margin thresholds and model-pricing assumptions.

Current gaps:
- Need dedicated immutable `ai_usage_logs` model/table (or equivalent immutable ledger view) as source of truth.
- Need margin dashboard wiring that joins revenue + AI cost + infra cost.
- Need token-burn anomaly detection against baseline windows.

### Layer 3: Infrastructure and reliability (TIG + minimal CloudWatch + Sentry)

Theory requirements mapped:
- TIG as primary time-series stack (Telegraf collector, InfluxDB store, Grafana dashboards/alerts).
- Sentry for discrete failures, release regressions, traces, breadcrumbs, and user context.
- Distinguish metrics (continuous trend) from logs/errors (debug context).

AWS interaction mapping:
- Keep CloudWatch only for hard dependencies and rebuilt fleet autoscaling contract (ADR-0005, fleet-only metrics).
- Add TIG pipeline as main reliability and economics dashboard plane.
- Keep Sentry external service integration for app-level observability and release correlation.

Ownership:
- Platform/SRE own infra metrics and alerting topology.
- App teams own instrumentation (latency/failure/tokens/release tags).

Current gaps:
- TIG deployment and retention policy not yet provisioned in CDK.
- Release annotations and Sentry->dashboard correlation are not standardized.
- Alert routing matrix (warning vs critical channels) needs codification.

### Alerts and operator loop (cross-layer control plane)

Theory-required categories:
- Reliability alerts: failure rate, queue backlog, job failures.
- Performance alerts: API/AI latency, saturation indicators.
- Cost alerts: tokens/min spikes, daily burn anomalies.
- Revenue alerts: payment failures, renewal failures, revenue drop anomalies.

Operational principles from theory:
- Alert only on actionable signals.
- Use warning and critical thresholds.
- Use sustained windows, not one-point spikes.
- Include context in notifications (metric, value, threshold, release/version, feature/model).

AWS interaction mapping:
- CloudWatch alarms remain for fleet/infra hard dependencies.
- Grafana/TIG alerts become default for product and economics operations.
- Notifications route via SNS/ChatOps/PagerDuty escalation path.

---

## Deliverable 3: Product-graded phased plan

## Phase A: Baseline inventory and gap register (done by this implementation)

Goal:
- Establish an agreed source of truth for AWS interactions and theory mapping.

Scope:
- Complete inventory (provisioning, runtime app, runtime ops, planned DevNode controls).
- Layer mapping and ownership boundaries.
- Initial gap register and acceptance criteria.

Acceptance gates:
- Single inventory artifact exists and is reviewable.
- Every required theory layer has owner, data flow, and gap list.
- DevNode planned AWS interaction contract is explicit.

### Phase B: CloudWatch clean-break rebuild (fleet-critical hardening)

Goal:
- Replace legacy fleet metrics/scaling paths with a new ADR-0005 contract and autoscaling topology (no backward compatibility requirement).

Scope:
- Emit only ADR-0005 fleet metrics in `ComfyUI/Workers` (`FleetSlug` + `Stage` dimensions): `QueueDepth`, `BacklogPerInstance`, `ActiveWorkers`, `AvailableCapacity`, `JobProcessingP50`, `ErrorRate`, `LeaseExpiredCount`, `SpotInterruptionCount`.
- Remove legacy fleet signals (`FleetSloPressureMax`, `FleetSpotSignalCount20m`) and remove periodic capacity-controller path.
- Use Spot ASG autoscaling with min/desired zero, step scaling on queue arrival, target tracking on backlog, and queue-empty alarm to the shared scale-to-zero SNS/Lambda path.

Acceptance gates:
- Staging autoscaling works with 0→1→N→0 behavior.
- No deployed fleet stack references legacy pressure/signal metrics.
- Operator runbooks/docs describe the new contract and alarm topology.

### Phase C: Deploy TIG as primary observability plane

Goal:
- Move day-to-day observability from CloudWatch dashboards to TIG.

Scope:
- Provision Telegraf, InfluxDB, Grafana (managed or self-hosted decision).
- Build dashboards for reliability, throughput, and economics.
- Wire threshold alerts in Grafana for non-hard-dependency signals.

Acceptance gates:
- Dashboards show live data from staging.
- Alert test scenarios fire correctly and route by severity.
- Retention and cardinality policy prevents storage blow-ups.

### Phase D: Implement AI usage logs and economics rollups

Goal:
- Turn AI operations into financial control with immutable usage telemetry.

Scope:
- Add immutable usage log schema and ingestion.
- Implement request-level cost computation and rollups by user/feature/model/plan/day.
- Publish margin and burn dashboards from trusted aggregates.

Acceptance gates:
- Each AI request has immutable usage row with required fields.
- Daily cost and margin dashboards reconcile with billing sources.
- Anomaly detector flags token burn and cost variance beyond baseline.

### Phase E: AWS-managed DevNode lifecycle automation

Goal:
- Convert DevNode lifecycle from manual record-keeping to AWS-backed control.

Scope:
- Add backend admin APIs for start/stop/restart/status/heartbeat.
- Implement AWS orchestration path (ASG and/or pinned EC2 mode).
- Add endpoint registry sync, lifecycle audit events, and cost attribution tags.

Acceptance gates:
- Admin can start DevNode, observe readiness, run interactive flow, then stop successfully.
- Lifecycle events and failures are audit-traceable.
- DevNode health and cost metrics are visible in operator dashboards.

### Phase F: Operator readiness and governance

Goal:
- Operationalize the theory's daily/weekly control routine.

Scope:
- Define trigger thresholds and escalation matrix.
- Establish daily AI PM control panel (DAU, conversion, cost/day, margin, failure rate, latency).
- Establish weekly review pack (model mix, cost per feature/plan, regressions, anomaly review).

Acceptance gates:
- On-call/owner matrix exists with severity routing.
- Daily checklist is run and archived.
- Every critical incident can be traced to metric source and action log.

---

## Risk register and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Over-reliance on CloudWatch dashboards | Stack choice drift and tool sprawl | Enforce "CloudWatch only for hard dependencies" policy |
| TIG rollout without metric governance | Cardinality explosion and noisy dashboards | Define metric naming/tagging policy before broad rollout |
| Missing immutable AI usage logs | Margin blind spots and pricing errors | Make usage ledger a release blocker for economics features |
| DevNode automation with broad IAM | Security and blast-radius risk | Apply least-privilege policies and stage-scoped resources |
| Alert fatigue | Slow incident response | Warning/critical split, suppression windows, actionable-only alerts |
| Cross-layer identifiers not aligned | Cannot correlate behavior, cost, and reliability | Standardize correlation keys: `user_id`, `feature`, `model`, `timestamp`, `release` |

---

## Immediate execution backlog (next concrete work)

1. Finalize metric and event contracts (names, dimensions, ownership) as versioned docs.
2. Implement TIG stack decision and CDK deployment path.
3. Add immutable AI usage logging schema and rollups.
4. Implement AWS-backed DevNode start/stop/restart endpoints with audit trail.
5. Activate operator dashboard and alert routing with weekly review cadence.

