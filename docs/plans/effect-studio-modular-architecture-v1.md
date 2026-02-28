# Effect Studio — Modular Architecture & Implementation Plan (Iteration 1)

## Context

The current platform is a multi-tenant Laravel + Next.js AI video effects system with ComfyUI GPU fleets, token-based billing, and admin studio tooling. This plan defines the modular architecture for the next iteration, treating **economic profitability as the #1 priority** and **provider-agnostic workflow execution with automatic AI quality evaluation** as the core differentiator.

The goal: a user submits an example video (TikTok, Instagram, etc.) → AI analyzes it → system tests across multiple provider workflows → evaluates quality + cost + speed on REAL executions → selects the best → notifies user → admin approves → public gallery. Every step is economically tracked, every module is independently testable as a blackbox.

---

## System Modules (10 Modules)

Each module is a **bounded context** — logically and technically separable, with defined input/output contracts, independently testable.

### Module Map: New vs Existing

| # | Module | Exists? | Current Code | Work Needed |
|---|--------|---------|--------------|-------------|
| M1 | Video Intake | PARTIAL | `FileController`, `VideoController`, `PresignedUrlService`, multipart upload system | Extend: add transcoding, metadata extraction, event emission |
| M2 | Content Analysis Engine | NEW | — | Build: AI video analysis (scene detection, style classification, effect recognition) |
| M3 | Provider Registry | PARTIAL | `ComfyUiWorkflowFleet`, `ComfyUiGpuFleet`, fleet templates | Extend: add provider capability catalog, adapter class mapping, health tracking |
| M4 | Prompt Generation | NEW | `WorkflowAnalyzerService` (partial) | Build: content-analysis → provider-agnostic prompt generation |
| M5 | Workflow Orchestrator | PARTIAL | `EffectRunSubmissionService`, `ProcessAiJob`, `AiJobDispatch` | Extend: add saga pattern, parallel test dispatch, result aggregation |
| M6 | Execution Engine | EXISTS | `ComfyUiWorkerController`, worker poll/complete/fail, `WorkflowPayloadService` | Extend: add IProviderAdapter interface, adapter instantiation |
| M7 | Quality Evaluation | NEW | `OutputValidationService` (minimal: exists/size/mime only) | Build: AI quality scoring (fidelity, artifacts, style adherence, temporal consistency) |
| M8 | Economic Engine | PARTIAL | `RunCostModelService`, `TokenLedgerService`, `EconomicsSetting`, `PartnerUsageEvent/Price`, economics admin | **Major extension**: real-time marginality, routing policy, bottleneck classification, AI recommendations |
| M9 | Notification & Approval | PARTIAL | — (no notification system, approval only implicit in publish flow) | Build: user notifications, admin approval queue |
| M10 | Gallery & Publishing | EXISTS | `Effect`, `EffectRevision`, publish pinning, `EffectPublicationService`, gallery API | Extend: auto-publish from approval pipeline |

---

## Module Definitions & Contracts

### M1: Video Intake

**Owns:** Video upload, validation, proxy transcoding, metadata extraction, S3 storage
**Existing code to reuse:**
- `backend/app/Http/Controllers/VideoController.php` — CRUD
- `backend/app/Http/Controllers/FileController.php` — file uploads
- `backend/app/Services/PresignedUrlService.php` — S3 presigned URLs
- `frontend/src/lib/multipartUpload.ts` — chunked upload
- `frontend/src/lib/uploadPreviewStore.ts` — IndexedDB caching

**Input:** User uploads video via presigned S3 URL (existing flow)
**Output:** `VideoIngested` event → `{video_id, user_id, source_uri, normalized_uri, metadata: {duration_ms, resolution, fps, codec, file_size_bytes}}`
**New work:** FFprobe metadata extraction, proxy transcoding job, event emission to M2
**Event bus:** Redis Streams (single infrastructure, separate streams for business events vs telemetry)

**Blackbox test:** Submit known video files (valid/invalid/corrupt/oversized) → assert correct event payload or validation error

---

### M2: Content Analysis Engine

**Owns:** Scene detection, style classification, motion analysis, effect type recognition from example videos
**Existing code to reuse:**
- `backend/app/Services/WorkflowAnalyzerService.php` — pattern: schema-first AI analysis with versioned prompts
- `backend/app/Models/OpenAI.php` — LLM wrapper (rename/abstract to support multiple providers)

**Input:** `VideoIngested` event
**Output:** `ContentAnalyzed` event → `{scenes[], dominant_style, motion_pattern, detected_effects[], eligible_effect_types[], user_description}`
**New work:** Video frame extraction, multimodal LLM analysis via **Gemini Pro Vision** (default provider), style taxonomy
**AI Provider:** Gemini Pro Vision — strong multimodal video understanding, competitive pricing. Provider-agnostic abstraction planned for later.

**Blackbox test:** Feed known videos with hand-labeled analysis → assert classification accuracy within tolerance. Golden dataset: 10+ reference videos with expected outputs.

---

### M3: Provider Registry

**Owns:** Provider catalog (which workflows can do which effects), adapter class mapping, capability declarations, health status
**Existing code to reuse:**
- `backend/app/Models/ComfyUiGpuFleet.php` — fleet definitions (instance_types, max_size, capacity_type)
- `backend/app/Models/ComfyUiWorkflowFleet.php` — workflow ↔ fleet assignments
- `backend/app/Services/ComfyUiFleetTemplateService.php` — fleet templates from JSON
- `backend/app/Models/Workflow.php` — workflow definitions (comfyui_workflow_path, properties, output_node_id, workload_kind)

**Input:** Admin CRUD operations, health check results from M6
**Output:** Provider capability queries (sync), `ProviderStatusChanged` events
**New work:** Provider model (id, adapter_class, capabilities[], supported_effect_types[], health_score), admin UI for provider management

**Blackbox test:** Register provider → query capabilities → assert correct capability matching for effect types

---

### M4: Prompt Generation

**Owns:** Translating content analysis + effect type into provider-agnostic prompts
**Existing code to reuse:**
- `backend/app/Services/WorkflowAnalyzerService.php` — schema-first analysis pattern, prompt versioning (`promptVersion()`, `schemaVersion()`)

**Input:** `ContentAnalyzed` event + user preferences
**Output:** `PromptsGenerated` event → `{prompt_variants[]: {prompt_text, target_effect_type, parameters}}`
**New work:** Prompt templates per effect type, LLM-based prompt refinement, parameter extraction

**Blackbox test:** Feed known analysis results → assert generated prompts contain expected keywords, follow template structure

---

### M5: Workflow Orchestrator (Saga)

**Owns:** Job lifecycle (state machine), parallel test dispatch to N providers, result aggregation, best-result selection, approval routing
**Existing code to reuse:**
- `backend/app/Services/EffectRunSubmissionService.php` — `buildRuntimeEffectForPublicRun()`, `preparePayloadAndUnits()`, `submitPrepared()`
- `backend/app/Jobs/ProcessAiJob.php` — async dispatch
- `backend/app/Models/AiJobDispatch.php` — dispatch tracking (status, stage, work_units, processing_seconds, queue_wait_seconds)
- `backend/app/Services/StudioBlackboxRunnerService.php` — pattern: multi-run dispatch with cost reports

**Input:** `PromptsGenerated` event
**Output:** `TestBatchDispatched` (fan-out to N providers), `BestResultSelected`, `ApprovalRequested`

**Core flow:**
1. Read Economic Engine routing manifest from Redis → get ranked providers
2. Fan-out: dispatch same input to 2-3 providers in parallel (reuse `submitPrepared()` pattern)
3. Collect `TaskSucceeded` + `QualityEvaluated` events per provider
4. Rank results by composite score (quality × cost × speed weights)
5. Emit `BestResultSelected` → trigger M9

**Blackbox test:** Feed `PromptsGenerated` + mock Economic Engine manifest + mock M6 responses → assert correct fan-out count, correct winner selection, correct state machine transitions. Failure test: all providers fail → verify user notification + token refund.

---

### M6: Execution Engine

**Owns:** ComfyUI fleet communication, GPU job scheduling, provider adapter instantiation, retry logic
**Existing code to reuse:**
- `backend/app/Http/Controllers/ComfyUiWorkerController.php` — worker register/poll/heartbeat/complete/fail
- `backend/app/Services/WorkflowPayloadService.php` — `resolveProperties()`, `computeWorkUnits()`, `buildJobPayload()`
- `backend/app/Services/DevNodeInteractiveRunService.php` — pattern: submit to ComfyUI endpoint, poll /history, download output
- Worker polling flow: `AiJobDispatch` → worker leases → processes → reports completion

**New work:** `IProviderAdapter` interface + concrete adapters per provider/workflow type

```php
interface IProviderAdapter {
    public function execute(ExecutionTaskDTO $task): ExecutionResultDTO;
    public function supports(string $effectType): bool;
    public function healthCheck(): ProviderHealthDTO;
}
```

Each adapter translates the standardized `ExecutionTaskDTO` into provider-specific ComfyUI workflow JSON. Adding a new provider = implement adapter + create config + register in M3. Zero changes to M6 core.

**Input:** `ExecutionTask` DTO from M5
**Output:** `TaskSucceeded` / `TaskFailed` events with execution telemetry (cost_tokens, duration_ms, gpu_seconds, node_timings)

**Blackbox test:** Submit `ExecutionTask` with mock ComfyUI API → verify adapter translation correctness, retry behavior on failure, telemetry accuracy

---

### M7: Quality Evaluation

**Owns:** Perceptual quality scoring, artifact detection, style adherence measurement, comparative ranking
**Existing code to reuse:**
- `backend/app/Services/OutputValidationService.php` — pattern: validate output exists/size/mime (extend, don't replace)

**Input:** `TaskSucceeded` events with output media URIs
**Output:** `QualityEvaluated` event → `{quality_vector: {fidelity, artifacts, style_adherence, temporal_consistency}, composite_score, comparative_rank}`

**Quality is a multi-dimensional vector, NOT a single score.** This lets M8 (Economic Engine) learn user-preference-weighted scoring.

**New work:** AI-based quality assessment using **Gemini Pro Vision** (compare input reference vs output), perceptual hash distance, artifact detection

**Blackbox test:** Feed known-good and known-bad outputs → assert scoring discriminates correctly. Golden dataset: outputs with pre-calculated quality scores.

---

### M8: Economic Engine (THE #1 PRIORITY)

**Owns:** Real-time marginality tracking, cost/quality/speed optimization, provider routing policy, explore/exploit strategy, bottleneck classification, AI-driven recommendations
**Existing code to reuse:**
- `backend/app/Services/RunCostModelService.php` — cost modeling (pure calculation, zero deps: startup_seconds, busy_seconds, compute_rate, partner_cost → margin)
- `backend/app/Services/TokenLedgerService.php` — token ledger (credit, reserve, consume, refund)
- `backend/app/Models/EconomicsSetting.php` — token_usd_rate, spot_multiplier, instance_type_rates
- `backend/app/Models/PartnerUsageEvent.php` — per-execution provider usage (tokens, credits, cost_usd_reported)
- `backend/app/Models/PartnerUsagePrice.php` — provider pricing (usd_per_1m_input/output_tokens, usd_per_credit)
- `backend/app/Http/Controllers/Admin/EconomicsAnalyticsController.php` — cross-tenant unit economics aggregation
- `backend/app/Http/Controllers/Admin/PartnerUsageAnalyticsController.php` — provider usage breakdowns
- `frontend/src/app/admin/economics/page.tsx` — settings, partner pricing, unit economics tabs (50.8KB)

**What exists and works:**
- Per-task cost calculation (RunCostModelService): startup + busy + idle seconds × compute rate + partner cost
- Token ledger with reserve/consume/refund lifecycle
- Partner usage tracking per execution (provider, model, tokens, credits, cost)
- Partner pricing configuration (per provider/node/model)
- Unit economics admin: per-effect aggregation of tokens consumed, processing seconds, partner costs, margins
- Economics settings: token-to-USD rate, instance type rates

**What must be built (END-TO-END PRODUCTION QUALITY):**

#### 8A. Real-Time Marginality Tracker
- Per-provider, per-effect-type running margin calculation
- Three sliding windows: 1-hour (immediate problems), 24-hour (daily patterns), 7-day (trends)
- Formula: `MARGIN = (user_tokens_charged - actual_cost_in_tokens) / user_tokens_charged`
- Admin HUD: always-visible burn rate, margin %, forecast

#### 8B. Routing Policy Manifest (Redis)
- Generated every 15 minutes (or on emergency override)
- Published to Redis: ranked provider list per effect type with expected cost, quality, duration
- M5 (Orchestrator) reads this manifest for every new job — fast local read (<1ms)
- Decouples Economic Engine availability from execution path

#### 8C. Explore/Exploit Strategy
- Epsilon-greedy: 95% use best known provider, 5% intentionally route to alternatives for fresh data
- Shadow testing: new providers receive real workloads but results only stored for evaluation, never shown to users
- A/B testing mode: admin-triggered parallel comparison (same input → multiple providers)

#### 8D. AI-Driven Cost Optimization Recommendations
- Analyze REAL execution data → generate typed recommendations:
  - `PROVIDER_SWITCH` — "Switch provider X to Y: +26% margin, -3% quality"
  - `PRICE_ADJUSTMENT` — "Raise price for effect Z: current margin 12%, target 35%"
  - `FLEET_OPTIMIZATION` — "Enable spot for non-priority: -40% compute cost"
  - `WORKFLOW_TUNING` — "Reduce steps from 30→20: -33% cost, -5% quality"
- Each recommendation includes: confidence, based_on_executions count, auto_applicable flag, requires_admin_approval flag

#### 8E. Bottleneck Classification Engine (Two-Stage)

**Stage 1 — Signal Detection:**
| Anomaly Type | Method | Detects |
|---|---|---|
| Gradual drift | CUSUM | Provider slowly degrading over days |
| Sudden spikes | Z-score (MAD) | Burst of errors, sudden cost jump |
| Seasonal | Holt-Winters | Peak hour cost increases |
| Threshold | Static + hysteresis | p95 latency > SLO, error rate > 5% |

**Stage 2 — Decision Tree Classifier (6 categories):**
1. `GPU_SATURATION` → Scale up ASG (auto if within budget)
2. `PROVIDER_LATENCY_DEGRADATION` → Deprioritize in routing manifest (auto)
3. `PROVIDER_API_THROTTLING` → Reduce concurrency (auto)
4. `TOKEN_DEPLETION_RISK` → Notify user, flag in admin (auto)
5. `WORKFLOW_INEFFICIENCY` → Flag for admin review, suggest parameter changes
6. `COLD_START_PENALTY` → Recommend minimum ASG capacity schedule

Each classification includes: recommended action, runbook URL, estimated cost impact, auto-action taken (if any).

#### 8F. Marginality Admin Dashboard (Sensitive — Admin Panel ONLY, never Grafana)
- Per-provider profit margin (real-time + historical charts)
- Per-effect-type revenue vs cost breakdown
- Token pricing recommendations (raise/lower based on margin targets)
- Provider comparison matrix: quality × cost × speed with REAL execution data
- Exploration budget burn rate
- Pending/approved/rejected recommendations with impact analysis
- **WHY marginality grows or falls** — drill into: which providers changed cost, which effects changed volume, which fleet costs changed

**Blackbox test:** Feed synthetic execution histories → assert routing manifest converges to optimal provider allocation. Feed known costs + known token charges → verify marginality calculations. Simulate provider failure → verify weight drops to near-zero in next policy cycle.

---

### M9: Notification & Approval

**Owns:** User notifications (email, push, in-app), admin approval queue
**Existing code to reuse:**
- Laravel notification system (built-in)
- Existing admin middleware and auth flow

**Input:** `BestResultSelected` event, `AdminApproved`/`AdminRejected` events
**Output:** `UserNotified`, `AdminApproved`/`AdminRejected` events

**User notification:** "Hey, we made this for you — try it!" with preview link
**Admin approval queue:** Quality scores, cost data, provider comparison side-by-side

**Blackbox test:** Feed events → verify correct notification dispatch, correct approval state transitions

---

### M10: Gallery & Publishing

**Owns:** Public effect catalog, immutable revisions, publish pinning, CDN
**Existing code to reuse:**
- `backend/app/Models/Effect.php` — name, slug, credits_cost, workflow_id, publication_status, published_revision_id, prod_execution_environment_id
- `backend/app/Models/EffectRevision.php` — immutable snapshot_json
- `backend/app/Services/EffectPublicationService.php` — publish/unpublish, environment resolution
- `backend/app/Services/EffectRevisionService.php` — `createSnapshot()`
- Public API: `GET /effects`, `GET /effects/{slugOrId}`, `GET /categories`
- Frontend: `EffectsLibraryClient`, `EffectDetailClient`, `EffectCard`

**Input:** `AdminApproved` event
**Output:** `EffectPublished` event → triggers user notification

**New work:** Wire `AdminApproved` event → auto-create revision → publish → notify user

**Blackbox test:** Feed `AdminApproved` event → verify immutable revision created, publish pin set, CDN distribution triggered, gallery listing updated

---

## End-to-End User Flow

```
USER UPLOADS EXAMPLE VIDEO (TikTok link or file)
    │
    ▼
[M1: Video Intake] ──→ VideoIngested event
    │
    ▼
[M2: Content Analysis] ──→ ContentAnalyzed event
    │                        (scenes, style, effects, eligible types)
    ▼
[M4: Prompt Generation] ──→ PromptsGenerated event
    │                         (provider-agnostic prompt variants)
    ▼
[M5: Orchestrator] reads [M8: Economic Engine] routing manifest from Redis
    │               queries [M3: Provider Registry] for eligible providers
    │
    ▼
[M5: Orchestrator] ──→ TestBatchDispatched (fan-out to 2-3 providers)
    │
    ├──→ [M6: Execution Engine] Provider A (SVD workflow) ──→ TaskSucceeded
    ├──→ [M6: Execution Engine] Provider B (AnimateDiff)  ──→ TaskSucceeded
    └──→ [M6: Execution Engine] Provider C (exploration)  ──→ TaskSucceeded
    │
    ▼ (parallel, per completed task)
[M7: Quality Evaluation] ──→ QualityEvaluated (per provider)
    │
    ▼ (continuous, async)
[M8: Economic Engine] ingests all telemetry (cost, duration, quality)
    │
    ▼ (when all results in or timeout)
[M5: Orchestrator] selects best ──→ BestResultSelected
    │
    ├──→ [M9: Notification] ──→ User: "We made this for you!"
    └──→ [M9: Approval Queue] ──→ Admin reviews (quality + cost + comparison)
    │
    ▼ (admin approves)
[M10: Gallery] ──→ Creates revision, publishes, CDN ──→ EffectPublished
    │
    ▼
[M9: Notification] ──→ User: "Your effect is live in the gallery!"
```

---

## Admin Flows

### Economic Dashboard Flow
- Admin opens `/admin/economics` → sees real-time margin HUD
- Drills into per-provider margins → sees which providers are profitable
- Views AI recommendations → approves/rejects provider switches
- Checks bottleneck classifications → sees auto-actions taken + manual recommendations
- Views exploration budget → adjusts epsilon rate if needed

### Effect Studio Flow (existing, enhanced)
- Admin opens `/admin/studio` → creates/clones effects and workflows
- JSON editor for workflow modification
- Interactive runs on dev nodes → blackbox runs on staging fleet
- Cost model calculator → unit economics per effect
- **New:** approval queue for auto-generated effects from user submissions

### Workload Monitoring Flow (existing, extended with TIG)
- Admin opens `/admin/workload` → matrix of workflows × workers
- **New:** links to Grafana dashboards for GPU fleet, provider performance, bottleneck monitor
- Sensitive economic data stays in admin panel

---

## Action-Oriented Logging

Every WARN/ERROR/CRITICAL log follows this schema:

```json
{
  "event_type": "OPERATIONAL_METRIC_THRESHOLD_CROSSED",
  "severity": "WARN",
  "source_module": "ExecutionEngine",
  "correlation_id": "job_xyz",
  "economic_impact": {
    "type": "QUALITY_DEGRADATION",
    "estimated_cost_usd": 2.40,
    "affected_jobs": 15
  },
  "operator_action": {
    "classification": "PROVIDER_LATENCY_DEGRADATION",
    "urgency": "MONITOR",
    "suggested_action": "Economic Engine will auto-deprioritize in next cycle...",
    "auto_action_taken": "Reduced provider weight from 0.85 to 0.60",
    "escalation_chain": ["auto_fix", "operator_monitor", "admin_alert_if_critical"],
    "runbook_url": "/runbooks/provider-latency"
  }
}
```

**Rule:** No log without `economic_impact`. No WARN+ log without `operator_action`. If you can't quantify the impact, use DEBUG.

**AI Anomaly Detection:** Log aggregation → pattern extraction (1h sliding window) → Isolation Forest anomaly detection → `EconomicAlert` events → admin panel + optional PagerDuty/Slack for CRITICAL.

---

## Three-Tier Monitoring

| Tier | Technology | What It Shows | Why |
|---|---|---|---|
| 1 | TIG Stack (Telegraf → InfluxDB → Grafana) | GPU utilization, queue depths, provider latency, job pipeline, container health, bottleneck classifications | Standard infra, non-sensitive |
| 2 | CloudWatch | ECS health, ASG events, S3 metrics, ALB, VPC flow logs | AWS-native, TIG can't reach |
| 3 | Admin Panel (app) | Profit margins, pricing analysis, revenue breakdowns, provider contract rates, economic recommendations | **Sensitive data — NEVER in Grafana** |

Cross-reference via `correlation_id` across all three tiers. Admin panel links to Grafana time-range for infra context.

---

## Blackbox Testing Strategy

Every module tested as opaque box with defined inputs → expected outputs.

| Module | Input Fixtures | Output Assertions | Special Tests |
|---|---|---|---|
| M1 Video Intake | Valid/invalid/corrupt video files | Correct event payload or validation error | Oversized, wrong codec, zero-length |
| M2 Content Analysis | `VideoIngested` events with known videos | Classification matches golden reference (±tolerance) | 10+ golden reference videos |
| M3 Provider Registry | Admin CRUD ops, health results | Correct capability matching | Provider goes unhealthy → excluded from queries |
| M4 Prompt Generation | `ContentAnalyzed` events | Prompts contain expected keywords, follow templates | Regression against golden prompt set |
| M5 Orchestrator | `PromptsGenerated` + mock manifest + mock M6 | Correct fan-out, correct winner, correct state transitions | All providers fail → refund + notification |
| M6 Execution Engine | `ExecutionTask` DTOs | Correct adapter translation, telemetry accuracy | Mock ComfyUI + real ComfyUI integration |
| M7 Quality Eval | Known output videos | Scores discriminate good vs bad | Perceptual hash distance tests |
| M8 Economic Engine | Synthetic execution histories | Routing manifest converges to optimal | Provider failure → weight drops; cost spike → alert |
| M9 Notification | `BestResultSelected` events | Correct dispatch, correct state | Delivery failure → retry |
| M10 Gallery | `AdminApproved` events | Revision created, publish pin set | CDN distribution, gallery listing |

**End-to-end trace test:** Upload video → propagate `correlation_id` → poll for `EffectPublished` → query logs → verify ALL intermediate events in correct order.

**Consumer-driven contract tests (Pact pattern):** Each consumer defines the schema it expects from a producer. Producers are tested against all consumer contracts before deployment.

---

## Economic Testing on Real Executions

The system must support **automatic economic evaluation on actually executed tasks:**

1. **Benchmark Suite:** A library of existing effects with known-good test inputs
2. **Multi-Provider Execution:** Same benchmark input → dispatched to all eligible providers → real GPU execution
3. **Automatic Evaluation:** M7 (Quality) scores each result + M8 (Economic Engine) tracks real costs
4. **Comparison Report:** Provider × Effect matrix with: quality score, processing time, cost per run, margin
5. **Decision Support:** AI recommendation: "For effect X, switch from provider A to B — saves $0.03/run (+18% margin) with -2% quality"
6. **Scheduled Runs:** Benchmark suite re-executed weekly (or on provider change) to keep data fresh

This builds on the existing `StudioBlackboxRunnerService` pattern (submit via public API, track costs) but adds multi-provider fan-out and quality comparison.

---

## Implementation Phases

### Phase 1: Foundation & Contracts (Weeks 1-3)
- Define all DTOs as PHP classes (extend existing `WorkflowPayloadService` pattern)
- Implement `IProviderAdapter` interface + first concrete adapter
- Set up event infrastructure (Redis Streams — single infra, separate streams for business events vs economic telemetry)
- Extend Provider Registry with capability catalog
- **Test:** Unit tests for all DTOs, adapter contract tests

### Phase 2: Economic Engine Core (Weeks 4-7) — TOP PRIORITY
- Real-time marginality tracker (per-provider, per-effect, sliding windows)
- Routing policy manifest generator (Redis publish every 15 min)
- Explore/exploit strategy (epsilon-greedy 95/5)
- AI recommendation engine (provider switch, price adjustment, fleet optimization)
- Admin marginality dashboard — end-to-end production quality
- **Test:** Scenario-based tests (provider cost changes → manifest updates)

### Phase 3: Analysis & Generation Pipeline (Weeks 8-11)
- M2: Content Analysis Engine (multimodal LLM video analysis)
- M4: Prompt Generation (content analysis → provider-agnostic prompts)
- M7: Quality Evaluation (AI quality scoring with multi-dimensional vector)
- **Test:** Golden dataset tests, quality discrimination tests

### Phase 4: Orchestrator & E2E Flow (Weeks 12-15)
- M5: Workflow Orchestrator saga (state machine, parallel dispatch, result aggregation)
- M9: Notification & Approval (user notifications, admin approval queue)
- Wire full user flow: upload → analyze → prompt → dispatch → evaluate → select → notify → approve → publish
- **Test:** Full saga tests, E2E trace tests, failure/compensation tests

### Phase 5: Intelligence & Monitoring (Weeks 16-19)
- Bottleneck classification engine (signal detection + decision tree)
- Action-oriented logging across all modules
- TIG stack integration (Telegraf + InfluxDB + Grafana dashboards)
- AI anomaly detection on log streams
- **Test:** Fault injection, bottleneck classification accuracy, alert tests

### Phase 6: Economic Benchmarking & Hardening (Weeks 20-22)
- Benchmark suite for economic testing on real executions
- Multi-provider comparison pipeline
- Consumer-driven contract tests for all module boundaries
- Load test full pipeline at 10x expected traffic
- **Test:** End-to-end economic benchmarking, load testing

---

## Deferred to Iteration 2

| Feature | Reason |
|---|---|
| AI Content Autopilot (clip extraction, social packs) | Requires stable pipeline first |
| Social Publishing Calendar | External tool integration (Mixpost) |
| Performance Learning Loop (retention/CTR correlation) | Needs social publishing data |
| Brand/Style System | Needs user engagement data |
| Revenue Split / Marketplace | Iteration 3 — requires compliance |
| Agency/Shared Budget module | Future module — tenants are for horizontal scaling only |
| Hardware Orchestration (DMX, OSC, MIDI) | Out of scope for current app |
| Onboarding Flow | Out of scope |
| Sync Primitives (Timecode/PTP) | Not necessary for current app |

---

## Key Files to Modify/Extend

**Backend Services (extend):**
- `backend/app/Services/RunCostModelService.php` → extend with sliding window marginality
- `backend/app/Services/TokenLedgerService.php` → add per-user budget enforcement
- `backend/app/Services/EffectRunSubmissionService.php` → add multi-provider dispatch
- `backend/app/Services/WorkflowPayloadService.php` → adapter-aware payload building
- `backend/app/Services/OutputValidationService.php` → extend into quality evaluation

**Backend Services (new):**
- `backend/app/Services/ContentAnalysisService.php`
- `backend/app/Services/PromptGenerationService.php`
- `backend/app/Services/WorkflowOrchestratorService.php`
- `backend/app/Services/QualityEvaluationService.php`
- `backend/app/Services/RoutingPolicyService.php`
- `backend/app/Services/BottleneckClassifierService.php`
- `backend/app/Services/EconomicRecommendationService.php`
- `backend/app/Contracts/IProviderAdapter.php`
- `backend/app/Adapters/` — concrete adapter classes

**Backend Models (new):**
- Provider, ProviderCapability, ProviderHealth
- OrchestrationJob (saga state machine)
- QualityScore
- RoutingPolicy, RoutingManifestSnapshot
- BottleneckClassification
- EconomicRecommendation
- Notification, ApprovalQueueEntry

**Frontend (extend):**
- `frontend/src/app/admin/economics/page.tsx` → real-time marginality HUD, recommendations
- `frontend/src/app/admin/workload/page.tsx` → TIG links, bottleneck status

**Frontend (new):**
- User video submission page (upload example + describe desired effect)
- User notification inbox
- Admin approval queue page
- Provider management admin page
- Bottleneck dashboard admin page

**Infrastructure (new):**
- TIG stack (Telegraf + InfluxDB + Grafana) deployment
- Redis Streams configuration (business events stream + economic telemetry stream)

---

## Verification Plan

1. **Unit tests:** Every service method, every DTO validation, every adapter
2. **Blackbox tests per module:** Input fixtures → expected output assertions (see table above)
3. **Contract tests:** Consumer-driven contracts for all inter-module events
4. **Integration tests:** Full pipeline with real ComfyUI (staging fleet)
5. **Economic benchmark tests:** Multi-provider comparison on real GPU executions
6. **Load tests:** Full pipeline at 10x expected traffic
7. **Fault injection:** Provider failure, GPU saturation, queue overflow scenarios
8. **E2E trace tests:** Upload → gallery with `correlation_id` verification across all modules
