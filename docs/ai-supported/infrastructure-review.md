# Infrastructure Review (AWS CDK)

This document summarizes the current gaps, cost risks, and operational risks in the
AWS CDK infrastructure, and provides a targeted risk register with mitigations.

## Deploy Blockers (must fix before go-live)

1. **Stage config selection is wrong**: production uses staging settings.
2. **CDK context not wired**: `domainName`, `certificateArn`, `alertEmail` are not
   read from context, so HTTPS and alerts can be silently disabled.
3. **GPU workers cannot reach backend when ALB is HTTP-only**: GPU worker SG allows
   outbound 443 only, but ALB stays on 80 without a cert.
4. **ECS task definitions cannot start**:
   - Placeholder images are referenced instead of ECR images.
   - ECS service and task family names do not match CI/CD expectations.
5. **Backend secrets missing**:
   - `APP_KEY` is created but never injected into ECS.
   - `COMFYUI_FLEET_SECRET` is required but not injected, so worker registration fails.
6. **RDS init custom resource is broken**:
   - `pymysql` is not packaged in the Lambda.
   - Lambda uses the RDS SG (no egress + no self-ingress), so it cannot call
     Secrets Manager or connect to the DB.
7. **GPU AMI bootstrapping mismatch**:
   - Worker script is copied into Packer repo but never installed in `/opt/worker`.
   - User-data runs `python3` instead of the AMI venv, so runtime deps are missing.
8. **Scale-to-zero SNS topic is not stage-scoped**, causing collisions across stages.

## Architecture Mismatches / Gaps

- **Intended HTTPS path is not enforced**: the ALB stays HTTP-only unless
  a certificate is wired, but worker SG enforces HTTPS-only egress.
- **RDS encryption is suppressed, not enabled**: staging currently has unencrypted
  storage, which conflicts with baseline security expectations.
- **VPC flow logs are suppressed but not implemented**: observability gap for
  incident investigation.
- **AMI build pipeline does not ensure worker script/venv alignment**: boot-time
  failures are likely without manual fixes.

## Cost Risk Hotspots (unpredictable spend)

- **NAT Gateway data processing**: GPU workers in private subnets calling a public
  ALB drive NAT data processing charges and cross-AZ data transfer.
- **CloudWatch Logs volume**: Laravel/Next logs can grow rapidly without retention
  tuning and log-level control.
- **RDS autoscaling + Performance Insights**: storage can grow up to max silently,
  and PI adds recurring cost.
- **GPU Spot “scale-to-zero” leakage**: incorrect scaling or stuck scale-in protection
  can leave instances running.
- **S3/CloudFront egress**: delivered media is the main cost driver at scale; lifecycle
  and caching must be tuned.

## Risk Register (targeted)

| Risk | Trigger | Impact | Mitigation | Owner |
|------|---------|--------|------------|-------|
| GPU workers fail to register | Missing `COMFYUI_FLEET_SECRET` in ECS | No jobs processed | Inject secret from SSM/Secrets Manager; add startup checks | Platform |
| AMI boots but worker crashes | `python3` used outside venv or missing script | Queue backlog, stuck scale-in protection | Install worker into AMI and run via venv/systemd | Platform |
| NAT data charges spike | High outbound traffic to ALB/S3 | Surprise monthly costs | Add VPC endpoints, consider internal ALB, monitor NAT bytes | Platform/Finance |
| Stuck GPU instances | Scale-in protection never cleared | Cost leak | Ensure worker clears protection, add alarms on ActiveWorkers vs QueueDepth | Platform |
| RDS init fails on deploy | Missing `pymysql` and blocked egress | Stack rollback | Replace with ECS bootstrap task or fix Lambda packaging/SGs | Platform/Data |
| HTTPS not enabled | Missing cert/context wiring | Worker connectivity failure + security risk | Wire CDK context and enforce HTTPS | Platform |
| CloudWatch log bill spikes | Verbose app logs | Monthly cost drift | Set retention, log levels, and dashboards/alarms | Platform/Ops |
| CloudFront/S3 egress spikes | Popular media downloads | Cost and throttling | Cache policy + lifecycle + budget alarms | Platform/Finance |

## Immediate Actions (1–2 days)

1. Fix stage config wiring + CDK context injection.
2. Make ComputeStack deployable: ECR images, stable ECS names, secret injection.
3. Fix GPU worker AMI + boot path (worker script + venv + HTTPS alignment).
4. Replace or repair RDS init.
5. Add cost guardrails (budgets + tags + NAT/log visibility).
