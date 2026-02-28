# AI Video Effects Platform -- Modular Architecture Deep Analysis

> Generated via three-pass deep architectural analysis (gemini-2.5-pro, max thinking depth)

---

## TABLE OF CONTENTS

1. [Module Boundaries (10 Modules)](#1-module-boundaries)
2. [Input/Output Contracts Between Modules](#2-inputoutput-contracts-between-modules)
3. [Economic Evaluation Pipeline End-to-End](#3-economic-evaluation-pipeline-end-to-end)
4. [Complete User Flow Mapped Across All Modules](#4-complete-user-flow-mapped-across-all-modules)
5. [Provider Switching Architecture](#5-provider-switching-architecture)
6. [Bottleneck Classification and Recommendation Engine](#6-bottleneck-classification-and-recommendation-engine)
7. [Action-Oriented Logging with AI Anomaly Detection](#7-action-oriented-logging-with-ai-anomaly-detection)
8. [Three-Tier Monitoring Architecture (TIG + CloudWatch + Admin Panel)](#8-three-tier-monitoring-architecture)
9. [Blackbox Testing Strategy Per Module](#9-blackbox-testing-strategy-per-module)
10. [Architectural Decisions and Trade-offs](#10-architectural-decisions-and-trade-offs)
11. [Actionable Next Steps](#11-actionable-next-steps)

---

## 1. MODULE BOUNDARIES

The platform decomposes into **10 independent modules**, each a bounded context with clear ownership. The decomposition follows Domain-Driven Design principles.

### Module 1: Video Intake
- **Owns:** Video upload, validation, transcoding, storage
- **Does NOT own:** Analysis of video content, prompt generation
- **Data owned:** Raw video files (S3), upload metadata, transcoding status
- **Input:** Raw user upload (multipart form / presigned URL)
- **Output:** `VideoIngested` event with normalized video URI + metadata
- **Failure mode:** Validation rejection, transcoding failure -> user notified with retry option

### Module 2: Content Analysis Engine (renamed from "AI Analysis Engine")
- **Owns:** Scene detection, object recognition, style classification, temporal analysis
- **Does NOT own:** Prompt generation, provider selection
- **Data owned:** Analysis results, content metadata cache
- **Input:** `VideoIngested` event
- **Output:** `ContentAnalyzed` event with structured metadata (scenes, objects, styles, motion patterns)
- **Why renamed:** Prevents confusion with Execution Engine. This module analyzes EXISTING content; it does not GENERATE new content.

### Module 3: Provider Registry
- **Owns:** Provider catalog, capability declarations, adapter class mappings, workflow JSON configurations, health status
- **Does NOT own:** Provider selection logic (that's Economic Engine), execution (that's Execution Engine)
- **Data owned:** Provider table (id, name, adapter_class, config_path, capabilities[], supported_effect_types[], status, health_score)
- **Input:** Admin CRUD operations, health check results
- **Output:** Provider capability queries (synchronous), `ProviderStatusChanged` events

### Module 4: Prompt Generation
- **Owns:** Translating content analysis + effect type into provider-agnostic prompts
- **Does NOT own:** Content analysis, provider-specific prompt formatting (that's the adapter's job)
- **Data owned:** Prompt templates, generation history
- **Input:** `ContentAnalyzed` event + requested effect type
- **Output:** `PromptsGenerated` event with prompt variants for testing

### Module 5: Workflow Orchestrator
- **Owns:** Job lifecycle management, parallel test dispatching, result aggregation, admin approval workflow
- **Does NOT own:** Actual execution, quality evaluation, economic decisions
- **Data owned:** Job state machine, orchestration DAG
- **Pattern:** Saga Orchestrator (not choreography) -- chosen because the video processing pipeline is long-running with complex failure/compensation handling
- **Input:** `PromptsGenerated` event
- **Output:** `TestBatchDispatched`, `BestResultSelected`, `ApprovalRequested`, `EffectPublished` events

### Module 6: Execution Engine
- **Owns:** Communication with ComfyUI fleet, GPU job scheduling, adapter instantiation, retry logic
- **Does NOT own:** Which provider to use (decided by Orchestrator + Economic Engine), quality evaluation
- **Data owned:** Execution logs, GPU utilization metrics, ComfyUI queue state
- **Input:** `ExecutionTask` DTO from Orchestrator
- **Output:** `TaskSucceeded` / `TaskFailed` events with execution telemetry

### Module 7: Quality Evaluation
- **Owns:** Perceptual quality scoring, artifact detection, style adherence measurement, comparative ranking
- **Does NOT own:** Economic cost analysis, provider selection
- **Data owned:** Quality scores history, evaluation models, golden reference datasets
- **Input:** `TaskSucceeded` events with output media URIs
- **Output:** `QualityEvaluated` event with multi-dimensional quality vector (NOT a single score)
- **Quality vector:** `{ fidelity: 0.9, artifacts: 0.1, style_adherence: 0.85, temporal_consistency: 0.92 }`

### Module 8: Economic Engine (THE #1 PRIORITY MODULE)
- **Owns:** Real-time marginality tracking, cost/quality/speed optimization, provider routing policy, explore/exploit strategy, bottleneck classification
- **Does NOT own:** Actual execution, quality measurement (consumes those as inputs)
- **Data owned:** Cost ledger, performance history, routing policies, marginality reports, bottleneck classifications
- **Operating model:** ACTIVE participant (not passive listener) -- publishes routing policies AND can emit control events like `UserQuotaExceeded`
- **Input:** Consumes `TaskSucceeded`, `TaskFailed`, `QualityEvaluated` events from dedicated telemetry stream; also listens to main bus for business events
- **Output:** Routing Policy Manifest (published to Redis every 15 min), `ProviderRecommendation` responses, `EconomicAlert` events, marginality dashboards

### Module 9: Notification & Approval
- **Owns:** User notifications (email, push, in-app), admin approval queue, approval/rejection workflow
- **Does NOT own:** Effect publishing, quality decisions
- **Data owned:** Notification history, approval queue state
- **Input:** `BestResultSelected` event, `ApprovalRequested` event
- **Output:** `UserNotified`, `AdminApproved` / `AdminRejected` events

### Module 10: Gallery & Publishing
- **Owns:** Public effect catalog, immutable revision management, publish pinning, CDN distribution
- **Does NOT own:** Effect generation, approval decisions
- **Data owned:** Published effects, revision history, gallery metadata, CDN URLs
- **Input:** `AdminApproved` event
- **Output:** `EffectPublished` event, public API for gallery browsing

---

## 2. INPUT/OUTPUT CONTRACTS BETWEEN MODULES

### Core DTOs

#### VideoIngestedEvent
```json
{
  "event_type": "VideoIngested",
  "event_id": "evt_abc123",
  "timestamp": "2026-02-28T10:00:00.000Z",
  "correlation_id": "job_z9y8x7w6",
  "payload": {
    "video_id": "vid_001",
    "user_id": "usr_456",
    "tenant_id": "tnt_789",
    "source_uri": "s3://intake/raw/vid_001.mp4",
    "normalized_uri": "s3://intake/normalized/vid_001.mp4",
    "metadata": {
      "duration_ms": 15000,
      "resolution": "1080x1920",
      "fps": 30,
      "codec": "h264",
      "file_size_bytes": 4500000
    }
  }
}
```

#### ContentAnalyzedEvent
```json
{
  "event_type": "ContentAnalyzed",
  "correlation_id": "job_z9y8x7w6",
  "payload": {
    "video_id": "vid_001",
    "analysis": {
      "scenes": [
        { "start_ms": 0, "end_ms": 5000, "description": "person dancing outdoors", "objects": ["person", "trees", "sky"] }
      ],
      "dominant_style": "cinematic_warm",
      "motion_pattern": "high_movement",
      "detected_effects": ["slow_motion", "color_grade_warm", "lens_flare"],
      "eligible_effect_types": ["style_transfer", "motion_enhancement", "color_correction"]
    }
  }
}
```

#### RecommendationRequest (Orchestrator -> Economic Engine, synchronous via Redis manifest)
```json
{
  "task_type": "style_transfer",
  "effect_subtype": "cinematic_warm",
  "user_preferences": { "priority": "quality" },
  "content_metadata": { "scene": "outdoors", "motion": "high", "duration_ms": 15000 },
  "budget_tokens": 500
}
```

#### ProviderRecommendation (Economic Engine response, from Redis routing manifest)
```json
{
  "recommended_providers": [
    {
      "provider_id": "comfy-svd-v1",
      "expected_cost_tokens": 150,
      "expected_quality_score": { "fidelity": 0.92, "style_adherence": 0.88 },
      "expected_duration_ms": 45000,
      "confidence": "high",
      "reason": "best_quality_for_type"
    },
    {
      "provider_id": "comfy-animatediff-v2",
      "expected_cost_tokens": 80,
      "expected_quality_score": { "fidelity": 0.78, "style_adherence": 0.82 },
      "expected_duration_ms": 22000,
      "confidence": "medium",
      "reason": "exploration_candidate"
    }
  ],
  "exploration_mode": true,
  "exploration_percentage": 0.05
}
```

#### ExecutionTask (Orchestrator -> Execution Engine, via message queue)
```json
{
  "task_id": "task_uuid_1234",
  "correlation_id": "job_z9y8x7w6",
  "provider_id": "comfy-svd-v1",
  "workflow_id": "wf_cinematic_svd_v3",
  "prompt": "Cinematic warm color grade with subtle lens flare, maintaining original motion",
  "input_video_uri": "s3://intake/normalized/vid_001.mp4",
  "parameters": {
    "strength": 0.8,
    "seed": 42,
    "cfg_scale": 7.5,
    "steps": 30
  },
  "timeout_ms": 120000,
  "retry_policy": { "max_retries": 2, "backoff_ms": 5000 }
}
```

#### TaskSucceededEvent
```json
{
  "event_type": "TaskSucceeded",
  "task_id": "task_uuid_1234",
  "correlation_id": "job_z9y8x7w6",
  "payload": {
    "provider_id": "comfy-svd-v1",
    "workflow_id": "wf_cinematic_svd_v3",
    "output_video_uri": "s3://output/task_uuid_1234/result.mp4",
    "execution_telemetry": {
      "actual_cost_tokens": 165,
      "actual_duration_ms": 48230,
      "gpu_type": "A10G",
      "gpu_seconds": 42.5,
      "queue_wait_ms": 3200,
      "comfyui_node_timings": {
        "KSampler": 35000,
        "VAEDecode": 8000,
        "LoadCheckpoint": 5230
      }
    }
  }
}
```

#### QualityEvaluatedEvent
```json
{
  "event_type": "QualityEvaluated",
  "task_id": "task_uuid_1234",
  "correlation_id": "job_z9y8x7w6",
  "payload": {
    "quality_vector": {
      "fidelity": 0.91,
      "artifacts": 0.05,
      "style_adherence": 0.87,
      "temporal_consistency": 0.93,
      "perceptual_hash_distance": 0.12
    },
    "composite_score": 0.89,
    "comparative_rank": 1,
    "total_candidates": 3
  }
}
```

### Event Flow Matrix

| Producer | Event | Consumers |
|---|---|---|
| Video Intake | `VideoIngested` | Content Analysis Engine |
| Content Analysis Engine | `ContentAnalyzed` | Prompt Generation, Workflow Orchestrator |
| Prompt Generation | `PromptsGenerated` | Workflow Orchestrator |
| Workflow Orchestrator | `TestBatchDispatched` | Execution Engine (fan-out to N providers) |
| Execution Engine | `TaskSucceeded` | Quality Evaluation, Economic Engine |
| Execution Engine | `TaskFailed` | Workflow Orchestrator (retry/compensate), Economic Engine |
| Quality Evaluation | `QualityEvaluated` | Workflow Orchestrator, Economic Engine |
| Workflow Orchestrator | `BestResultSelected` | Notification & Approval |
| Notification & Approval | `AdminApproved` | Gallery & Publishing |
| Gallery & Publishing | `EffectPublished` | Notification & Approval (notify user) |
| Economic Engine | `EconomicAlert` | Admin Panel, Bottleneck Engine |
| Economic Engine | `UserQuotaExceeded` | Workflow Orchestrator (reject new jobs) |
| Economic Engine | `RoutingPolicyUpdated` | Redis manifest (Orchestrator reads) |

### Synchronous vs Asynchronous Boundaries

| Boundary | Type | Justification |
|---|---|---|
| Orchestrator -> Economic Engine (routing query) | **Synchronous** (Redis read) | Fast local read from cached manifest, <1ms |
| Orchestrator -> Execution Engine | **Asynchronous** (SQS/Redis queue) | Long-running GPU jobs, decoupled |
| Execution Engine -> Quality Evaluation | **Asynchronous** (event bus) | Evaluation can be deferred |
| All -> Economic Engine (telemetry) | **Asynchronous** (dedicated stream) | High volume, must not block critical path |
| Admin -> Provider Registry | **Synchronous** (API) | Low volume CRUD operations |
| User -> Video Intake | **Synchronous** (API for upload) then **Async** (processing) | Upload is sync, everything after is async |

---

## 3. ECONOMIC EVALUATION PIPELINE END-TO-END

### Architecture: Published Policy Model with Active Feedback Loop

The Economic Engine is the most complex module. It operates in two modes simultaneously:

#### Mode 1: Offline Policy Generation (Every 15 Minutes)
```
[Historical Data Store]
    |
    v
[Marginality Calculator] -- computes per-provider, per-effect-type margins
    |
    v
[Optimization Solver] -- multi-objective: quality, cost, speed with weights
    |
    v
[Explore/Exploit Balancer] -- epsilon-greedy (95% exploit, 5% explore)
    |
    v
[Routing Policy Manifest] -- published to Redis
    |
    v
[Workflow Orchestrator reads manifest for every new job]
```

#### Mode 2: Real-Time Event Processing (Continuous)
```
[TaskSucceeded / TaskFailed / QualityEvaluated events]
    |
    v
[Telemetry Ingestion] -- separate Kafka topic, NOT main event bus
    |
    v
[Real-Time Marginality Tracker]
    |   - Per-provider running cost (token cost vs GPU cost)
    |   - Per-effect-type running margin
    |   - Sliding window (1h, 24h, 7d)
    |
    v
[Anomaly Detector]
    |   - CUSUM for drift (provider slowly degrading)
    |   - Z-score for spikes (sudden cost increase)
    |
    v
[Bottleneck Classifier] -- see Section 6
    |
    v
[Emergency Policy Override] -- if critical anomaly, push new manifest immediately (not wait 15 min)
```

### Real-Time Marginality Tracking

For every executed task, the Economic Engine computes:

```
MARGINALITY = (user_tokens_charged - actual_provider_cost_in_tokens) / user_tokens_charged

Where:
  user_tokens_charged = effect_base_price + quality_premium + speed_premium
  actual_provider_cost = gpu_seconds * gpu_cost_per_second + api_call_cost + storage_cost + bandwidth_cost
```

This is tracked in three sliding windows:
- **1-hour window:** Detects immediate problems (provider outage, cost spike)
- **24-hour window:** Detects daily patterns (peak hours, provider throttling)
- **7-day window:** Detects trends (provider gradually increasing costs, quality degradation)

### AI-Driven Cost Optimization Recommendations

The Economic Engine generates recommendations by analyzing REAL execution data:

```json
{
  "recommendation_id": "rec_001",
  "type": "PROVIDER_SWITCH",
  "priority": "HIGH",
  "analysis": {
    "current_provider": "comfy-svd-v1",
    "current_margin": 0.32,
    "recommended_provider": "comfy-animatediff-v2",
    "projected_margin": 0.58,
    "quality_impact": "-3% fidelity, +5% speed",
    "confidence": 0.87,
    "based_on_executions": 1247
  },
  "action": "Switch default provider for 'style_transfer' effect type from comfy-svd-v1 to comfy-animatediff-v2",
  "auto_applicable": false,
  "requires_admin_approval": true
}
```

### Automatic Provider/Workflow Comparison on REAL Tasks

This is the **explore/exploit** mechanism:

1. **Epsilon-Greedy Exploration (5%):** For 5% of user tasks, the system intentionally routes to a non-default provider to gather fresh performance data.
2. **A/B Testing Mode:** Admin can manually trigger a comparison where the same input video is processed by 2-3 providers simultaneously. Results are evaluated by Quality Evaluation module and costs tracked by Economic Engine. This happens during the `TestBatchDispatched` phase.
3. **Shadow Testing:** New providers can be added in "shadow mode" -- they receive real workloads but their results are only stored for evaluation, never shown to users. Zero risk to user experience.

### Marginality Dashboard (Admin Panel)

The Admin Panel displays sensitive economic data that should NOT be in Grafana:

- Per-provider profit margin (real-time + historical)
- Per-effect-type revenue vs cost breakdown
- Token pricing recommendations (raise/lower prices based on margin targets)
- Provider comparison matrix (quality x cost x speed) with real data
- Exploration budget burn rate

---

## 4. COMPLETE USER FLOW MAPPED ACROSS ALL MODULES

### Happy Path: User Upload to Gallery

```
STEP 1: USER UPLOADS VIDEO
  Module: Video Intake
  Action: User uploads a TikTok video via API (presigned S3 URL)
  Event emitted: VideoIngested
  DTO: { video_id, source_uri, normalized_uri, metadata }
  On failure: 400 validation error (bad format, too long, too large) -> user notified

STEP 2: AI ANALYZES VIDEO CONTENT
  Module: Content Analysis Engine
  Trigger: Consumes VideoIngested event
  Action: Runs scene detection, object recognition, style classification
  Event emitted: ContentAnalyzed
  DTO: { scenes[], dominant_style, motion_pattern, detected_effects[], eligible_effect_types[] }
  On failure: Retry 3x -> if still fails, mark job as "analysis_failed" -> user notified

STEP 3: PROMPTS GENERATED
  Module: Prompt Generation
  Trigger: Consumes ContentAnalyzed event
  Action: Generates provider-agnostic prompt variants based on analysis + effect type
  Event emitted: PromptsGenerated
  DTO: { prompt_variants[]: { prompt_text, target_effect_type, parameters } }
  On failure: Fallback to generic prompt template -> continue pipeline

STEP 4: ORCHESTRATOR QUERIES ECONOMIC ENGINE FOR ROUTING
  Module: Workflow Orchestrator + Economic Engine
  Action: Orchestrator reads Redis routing manifest to determine which providers to test
  Sync read: Routing Policy Manifest -> returns ranked provider list with exploration candidates
  Decision: Select top 2-3 providers (including 1 exploration candidate if epsilon triggers)

STEP 5: PARALLEL TEST DISPATCH (FAN-OUT)
  Module: Workflow Orchestrator -> Execution Engine
  Action: Dispatches N execution tasks in PARALLEL (not sequential) to different providers
  Event emitted: TestBatchDispatched (contains N ExecutionTask DTOs)
  Each task goes to a different provider/workflow combination
  On failure of dispatch: Retry queue insertion, dead-letter after 3 failures

STEP 6: EXECUTION ACROSS PROVIDERS (PARALLEL)
  Module: Execution Engine
  Trigger: Consumes ExecutionTask from queue
  Action per task:
    a) Query Provider Registry for adapter class + config
    b) Instantiate correct IProviderAdapter
    c) Adapter translates ExecutionTask -> ComfyUI-specific workflow JSON
    d) Submit to ComfyUI fleet (GPU ASG)
    e) Wait for completion (with timeout)
    f) Adapter translates ComfyUI response -> ExecutionResult
  Event emitted: TaskSucceeded (per provider) or TaskFailed
  DTO: { output_video_uri, execution_telemetry: { cost, duration, gpu_seconds, node_timings } }
  On failure: Retry per retry_policy -> TaskFailed event after exhaustion

STEP 7: QUALITY EVALUATION (PARALLEL, per completed task)
  Module: Quality Evaluation
  Trigger: Consumes each TaskSucceeded event
  Action: Evaluates output against input video and quality criteria
  Event emitted: QualityEvaluated
  DTO: { quality_vector: { fidelity, artifacts, style_adherence, temporal_consistency }, composite_score, comparative_rank }
  On failure: Mark quality as "unknown" with confidence 0 -> still usable but lower ranked

STEP 8: ECONOMIC ENGINE INGESTS TELEMETRY (CONTINUOUS)
  Module: Economic Engine
  Trigger: Consumes TaskSucceeded + QualityEvaluated events from dedicated telemetry stream
  Action: Updates marginality tracker, provider performance history, anomaly detectors
  No event emitted (internal state update)
  On failure: Telemetry is buffered in Kafka -> replay on recovery

STEP 9: ORCHESTRATOR SELECTS BEST RESULT
  Module: Workflow Orchestrator
  Trigger: All QualityEvaluated events received for the batch (or timeout reached)
  Action: Ranks results by composite score weighted by user preferences and cost
  Event emitted: BestResultSelected
  DTO: { winning_task_id, winning_provider_id, quality_vector, cost_tokens, comparison_summary }
  On failure: If zero successful results, notify user of failure with refund

STEP 10: USER NOTIFIED
  Module: Notification & Approval
  Trigger: Consumes BestResultSelected event
  Action: Sends notification to user (push/email/in-app) with preview of result
  Event emitted: UserNotified
  On failure: Retry notification delivery, log to dead-letter

STEP 11: ADMIN APPROVAL GATE
  Module: Notification & Approval
  Trigger: BestResultSelected event also triggers admin approval queue entry
  Action: Effect appears in Admin Studio approval queue with quality scores, cost data, comparison
  Admin reviews and approves/rejects
  Event emitted: AdminApproved or AdminRejected
  On AdminRejected: User notified, job marked as rejected, tokens refunded
  On failure: Approval queue is persistent, no data loss

STEP 12: PUBLISH TO GALLERY
  Module: Gallery & Publishing
  Trigger: Consumes AdminApproved event
  Action:
    a) Creates immutable effect revision
    b) Pins to current publish version
    c) Distributes to CDN
    d) Updates gallery catalog
  Event emitted: EffectPublished
  DTO: { effect_id, revision_id, gallery_url, cdn_urls }
  On failure: Retry CDN distribution, effect stays in "publishing" state

STEP 13: USER NOTIFIED OF PUBLICATION
  Module: Notification & Approval
  Trigger: Consumes EffectPublished event
  Action: Notifies user their effect is live in the gallery
  Final state: COMPLETE
```

---

## 5. PROVIDER SWITCHING ARCHITECTURE

### The Adapter Pattern for ComfyUI Workflow Abstraction

The core challenge: the same "cinematic color grade" effect might be achievable via completely different ComfyUI workflows using different partner nodes (e.g., one uses AnimateDiff nodes, another uses SVD nodes, a third uses a custom partner's proprietary nodes).

### Provider Data Model

```json
{
  "provider_id": "comfy-svd-v1",
  "display_name": "Stable Video Diffusion v1",
  "adapter_class": "App\\Adapters\\ComfyUI\\SVDAdapter",
  "config_path": "workflows/svd_v1_config.json",
  "workflow_json_path": "workflows/svd_v1_workflow.json",
  "capabilities": ["style_transfer", "img2vid", "vid2vid"],
  "supported_effect_types": ["cinematic", "anime", "abstract"],
  "node_dependencies": ["ComfyUI-SVD", "ComfyUI-VideoHelperSuite"],
  "cost_model": {
    "base_cost_per_frame": 0.002,
    "gpu_type_required": "A10G",
    "avg_gpu_seconds_per_frame": 1.2,
    "cold_start_penalty_seconds": 45
  },
  "health": {
    "status": "healthy",
    "last_check": "2026-02-28T10:00:00Z",
    "success_rate_24h": 0.97,
    "avg_latency_ms_24h": 42000
  }
}
```

### IProviderAdapter Interface (PHP/Laravel)

```php
interface IProviderAdapter
{
    /**
     * Translate a standardized ExecutionTask into a provider-specific
     * ComfyUI workflow payload and execute it.
     */
    public function execute(ExecutionTaskDTO $task): ExecutionResultDTO;

    /**
     * Validate that this adapter can handle the given effect type.
     */
    public function supports(string $effectType): bool;

    /**
     * Health check for this specific provider.
     */
    public function healthCheck(): ProviderHealthDTO;
}
```

### Concrete Adapter Example

```php
class SVDAdapter implements IProviderAdapter
{
    private string $apiEndpoint;
    private array $workflowTemplate;  // Loaded from workflow_json_path
    private array $nodeMapping;       // Maps generic params to specific node IDs

    public function execute(ExecutionTaskDTO $task): ExecutionResultDTO
    {
        // 1. Load workflow JSON template
        $workflow = $this->workflowTemplate;

        // 2. Inject task parameters into correct nodes
        $workflow[$this->nodeMapping['prompt_node']]['inputs']['text'] = $task->prompt;
        $workflow[$this->nodeMapping['sampler_node']]['inputs']['seed'] = $task->parameters['seed'];
        $workflow[$this->nodeMapping['sampler_node']]['inputs']['steps'] = $task->parameters['steps'];
        $workflow[$this->nodeMapping['video_input_node']]['inputs']['video'] = $task->inputVideoUri;

        // 3. Submit to ComfyUI API
        $response = $this->comfyClient->queuePrompt($workflow);

        // 4. Poll for completion
        $result = $this->comfyClient->waitForResult($response['prompt_id'], $task->timeoutMs);

        // 5. Translate back to standard DTO
        return new ExecutionResultDTO(
            outputVideoUri: $result['output_video_url'],
            actualCostTokens: $this->calculateCost($result),
            actualDurationMs: $result['execution_time_ms'],
            gpuSeconds: $result['gpu_time_seconds'],
            nodeTimings: $result['node_timings']
        );
    }
}
```

### Workflow Mapping Configuration (per adapter)

```json
{
  "adapter": "SVDAdapter",
  "node_mapping": {
    "prompt_node": "6",
    "negative_prompt_node": "7",
    "sampler_node": "3",
    "video_input_node": "12",
    "output_node": "9",
    "checkpoint_loader": "4"
  },
  "default_parameters": {
    "cfg_scale": 7.5,
    "denoise": 0.65,
    "sampler_name": "euler"
  },
  "required_models": ["svd_xt_1_1.safetensors"],
  "required_custom_nodes": ["ComfyUI-SVD", "ComfyUI-VideoHelperSuite"]
}
```

### A/B Testing Between Providers

During Step 5 (TestBatchDispatched), the Orchestrator dispatches the SAME input to multiple providers simultaneously. The Economic Engine's routing manifest determines which providers to include:

```
Input Video (vid_001) ─┬─> Provider A (SVD workflow)     ─> Quality Score + Cost
                       ├─> Provider B (AnimateDiff workflow) ─> Quality Score + Cost
                       └─> Provider C (exploration candidate) ─> Quality Score + Cost
```

All three run in parallel on the GPU fleet. Results are compared by Quality Evaluation module, costs tracked by Economic Engine. The Orchestrator picks the winner based on the weighted composite of quality, cost, and speed.

---

## 6. BOTTLENECK CLASSIFICATION AND RECOMMENDATION ENGINE

### Two-Stage Architecture: Signal Detection -> Classification

#### Stage 1: Signal Detection (Statistical Anomaly Detection)

Raw metrics from all modules feed into the signal detection layer. Different statistical methods are used for different anomaly types:

| Anomaly Type | Statistical Method | What It Detects |
|---|---|---|
| Gradual drift | **CUSUM** (Cumulative Sum) | Provider slowly getting more expensive or slower over days |
| Sudden spikes | **Z-score** (Modified, using MAD) | Sudden GPU unavailability, burst of errors |
| Seasonal patterns | **Exponential Smoothing** (Holt-Winters) | Peak hour cost increases, weekend pattern changes |
| Threshold breach | **Static thresholds** with hysteresis | p95 latency > 12s, error rate > 5%, queue depth > 100 |

Output: Discrete signals (not raw metrics):
- `P95_LATENCY_THRESHOLD_BREACHED`
- `ERROR_RATE_ABOVE_BASELINE`
- `QUEUE_DEPTH_SPIKE_DETECTED`
- `GPU_UTILIZATION_SATURATED`
- `COST_DRIFT_DETECTED`
- `COLD_START_FREQUENCY_ELEVATED`

#### Stage 2: Decision Tree Classifier

The detected signals become input features for classification into 6 bottleneck categories:

```
Category 1: GPU_SATURATION
  Signals: GPU_UTILIZATION_SATURATED + QUEUE_DEPTH_SPIKE
  Recommendation: "Scale up ASG desired capacity from 4 to 6. Estimated cost impact: +$12/hr. Alternative: enable spot instances for non-priority jobs."
  Auto-action: Trigger ASG scale-up if within budget

Category 2: PROVIDER_LATENCY_DEGRADATION
  Signals: P95_LATENCY_THRESHOLD_BREACHED + ERROR_RATE_NORMAL
  Recommendation: "Provider comfy-svd-v1 p95 latency increased 40% over 24h. Economic Engine will auto-deprioritize in next policy cycle (15 min). No immediate action needed unless CRITICAL."
  Auto-action: Economic Engine reduces provider weight in routing manifest

Category 3: PROVIDER_API_THROTTLING
  Signals: ERROR_RATE_ABOVE_BASELINE + specific_error_code_429
  Recommendation: "Provider is throttling requests. Reduce concurrent requests from 10 to 5. Consider distributing load to secondary provider."
  Auto-action: Execution Engine reduces concurrency for this provider

Category 4: TOKEN_DEPLETION_RISK
  Signals: High consumption rate + low wallet balance trend
  Recommendation: "Tenant tnt_789 projected to exhaust token balance in 4.2 hours at current rate. Notify user. Consider auto-top-up prompt."
  Auto-action: Send notification to user, flag in Admin Panel

Category 5: WORKFLOW_INEFFICIENCY
  Signals: COST_DRIFT_DETECTED + node_timing_outliers
  Recommendation: "Workflow wf_cinematic_svd_v3 KSampler node taking 35s avg (was 22s). Investigate checkpoint model size or reduce step count from 30 to 20."
  Auto-action: Flag workflow for Admin Studio review

Category 6: COLD_START_PENALTY
  Signals: COLD_START_FREQUENCY_ELEVATED + high_queue_wait_times
  Recommendation: "62% of jobs hitting cold start (>45s delay). Consider increasing ASG minimum from 0 to 1 during business hours (09:00-21:00 UTC). Cost: +$8/hr during those hours."
  Auto-action: Recommend schedule-based minimum capacity
```

#### Flow Diagram

```
Raw Metrics (Latency, Errors, Queue Depth, GPU%, Cost, Token Balance)
  |
  v
[Stage 1: Signal Detection Engine]
  |   - CUSUM for drift
  |   - Z-score for spikes
  |   - Holt-Winters for seasonal
  |   - Static thresholds with hysteresis
  v
Discrete Signals (P95_BREACH, ERROR_SPIKE, GPU_SATURATED, ...)
  |
  v
[Stage 2: Decision Tree Classifier]
  |   - Input: vector of recent signals
  |   - Output: bottleneck category + confidence
  v
Classification (e.g., 'PROVIDER_LATENCY_DEGRADATION', confidence: 0.91)
  |
  v
[Recommendation Engine]
  |   - Pre-defined runbook per category
  |   - Cost impact estimation
  |   - Auto-action if within policy
  v
Action: { alert, auto_fix, runbook_url, estimated_impact }
```

### Integration with TIG Stack

- **Telegraf:** Collects raw metrics from Execution Engine, GPU fleet, ComfyUI instances, queue depths
- **InfluxDB:** Stores time-series data; Signal Detection Engine queries InfluxDB for sliding window calculations
- **Grafana:** Displays bottleneck classification dashboard with real-time category assignments and historical trends

---

## 7. ACTION-ORIENTED LOGGING WITH AI ANOMALY DETECTION

### Log Entry Schema

Every log entry in this platform follows a structured schema that answers: "What happened, what is the economic impact, and what should an operator do?"

```json
{
  "timestamp": "2026-02-28T10:00:05.123Z",
  "event_id": "evt_a1b2c3d4e5f6",
  "source_module": "ExecutionEngine",
  "correlation_id": "job_z9y8x7w6",
  "tenant_id": "tnt_789",
  "event_type": "OPERATIONAL_METRIC_THRESHOLD_CROSSED",
  "severity": "WARN",

  "economic_impact": {
    "type": "QUALITY_DEGRADATION",
    "description": "15 jobs processed with p95 latency > 12s, potentially impacting user experience and causing token overcharge due to GPU idle time.",
    "estimated_cost_usd": 2.40,
    "estimated_revenue_impact_usd": 0.00,
    "affected_jobs": 15,
    "affected_users": 8
  },

  "details": {
    "message": "Provider 'comfy-svd-v1' p95 processing time is 15230ms, exceeding threshold of 12000ms.",
    "provider_id": "comfy-svd-v1",
    "metric_name": "p95_processing_time_ms",
    "metric_value": 15230,
    "threshold": 12000,
    "baseline_value": 9800,
    "deviation_percent": 55.4,
    "instance_id": "exec-engine-pod-7b8c9d"
  },

  "operator_action": {
    "classification": "PROVIDER_LATENCY_DEGRADATION",
    "urgency": "MONITOR",
    "suggested_runbook_url": "/runbooks/provider-latency-investigation",
    "suggested_action": "Economic Engine will auto-deprioritize this provider in next routing policy cycle (max 15 min). Monitor provider status page. Escalate to CRITICAL if p95 exceeds 20000ms.",
    "auto_action_taken": "Reduced provider weight from 0.85 to 0.60 in next routing manifest.",
    "escalation_chain": ["auto_fix", "operator_monitor", "admin_alert_if_critical"]
  }
}
```

### Key Design Principles

1. **No log without economic_impact:** If you cannot quantify the impact, the log should not exist at this level (use DEBUG for non-impactful entries)
2. **No log without operator_action:** Every WARN/ERROR/CRITICAL log MUST include what to do about it
3. **Escalation chain is explicit:** `auto_fix -> operator_monitor -> admin_alert_if_critical`

### AI Anomaly Detection on Log Streams

```
Log Stream (structured JSON)
  |
  v
[Log Aggregator] -- collects from all modules
  |
  v
[Pattern Extraction]
  |   - Frequency of event_types per module (sliding 1h window)
  |   - Correlation between event_types across modules
  |   - New event_type patterns not seen before
  v
[AI Anomaly Detector]
  |   - Trained on "normal" log patterns (baseline from 7-day history)
  |   - Detects: unusual event frequency, new error patterns, correlated failures
  |   - Uses: Isolation Forest for multivariate anomaly detection
  v
[Alert Generator]
  |   - Generates EconomicAlert events
  |   - Updates Admin Panel anomaly dashboard
  |   - Triggers PagerDuty/Slack for CRITICAL
```

### Log Separation by Destination

| Log Category | Destination | Reason |
|---|---|---|
| Infrastructure metrics (CPU, memory, network) | TIG Stack (Telegraf -> InfluxDB -> Grafana) | Standard infra monitoring, non-sensitive |
| AWS service metrics (ECS, ASG, S3) | CloudWatch | Native AWS integration, TIG not possible |
| Business/economic logs (marginality, pricing, revenue) | Admin Panel ONLY | Sensitive operational data, must not leak to Grafana |
| Operational events (provider health, job status) | TIG Stack + Admin Panel | Both need visibility, Grafana for trends, Admin for details |
| Security events (auth failures, rate limiting) | CloudWatch + Admin Panel | Audit trail requirements |

---

## 8. THREE-TIER MONITORING ARCHITECTURE

### Tier 1: TIG Stack (Telegraf + InfluxDB + Grafana)

**Telegraf Inputs:**
- `inputs.docker` -- container metrics from ECS tasks
- `inputs.nvidia_smi` -- GPU utilization, memory, temperature from ComfyUI fleet
- `inputs.redis` -- queue depths, connection counts, memory usage
- `inputs.statsd` -- custom application metrics pushed from Execution Engine
- `inputs.http_listener_v2` -- webhook metrics from ComfyUI completion callbacks
- `inputs.cloudwatch` (bridged) -- pull selected AWS metrics into InfluxDB for unified dashboards

**InfluxDB Measurements:**
- `gpu_utilization` -- tags: instance_id, gpu_type; fields: utilization_percent, memory_used_mb, temperature_c
- `job_execution` -- tags: provider_id, workflow_id, effect_type; fields: duration_ms, cost_tokens, queue_wait_ms
- `provider_health` -- tags: provider_id; fields: success_rate, p50_latency, p95_latency, error_count
- `queue_depth` -- tags: queue_name; fields: pending, processing, failed
- `asg_status` -- tags: asg_name; fields: desired, running, pending, spot_count

**Grafana Dashboards:**
1. **GPU Fleet Overview** -- real-time utilization, queue depths, scale events, cold starts
2. **Provider Performance** -- latency percentiles, error rates, throughput per provider
3. **Job Pipeline** -- jobs in each stage, completion rates, average time per stage
4. **Bottleneck Monitor** -- active bottleneck classifications, signal history, auto-action log
5. **Infrastructure Health** -- container health, network, Redis, S3 latency

### Tier 2: CloudWatch (Where TIG Cannot Reach)

- ECS service health, task placement failures
- ASG scaling events and decisions
- S3 request metrics and error rates
- Lambda execution (if any auxiliary functions)
- ALB/NLB request counts, latency, 5xx rates
- VPC flow logs for network debugging

### Tier 3: Admin Panel (Sensitive Operational Data)

- **Per-provider profit margins** (real-time + historical) -- NEVER in Grafana
- **Token pricing analysis** -- revenue vs cost per effect type
- **User spending patterns** -- high-value user identification
- **Provider cost negotiations data** -- contract rates, volume discounts
- **Economic Engine recommendations** -- pending, approved, rejected with impact analysis
- **Anomaly investigation workspace** -- drill-down from any alert to full event chain

### Cross-Reference Strategy

All three systems share:
1. **Correlation ID** (`correlation_id`) -- traces a single user request across all systems
2. **Timestamps** -- synchronized via NTP, all UTC
3. **Admin Panel deep links** -- Grafana alerts link to Admin Panel investigation page; Admin Panel links back to Grafana time-range for infra context
4. **CloudWatch Insights queries** -- accessible from Admin Panel for AWS-specific debugging without leaving the app

---

## 9. BLACKBOX TESTING STRATEGY PER MODULE

### Principle: Every module is tested as an opaque box with defined inputs and expected outputs.

### Per-Module Test Strategies

#### Video Intake
- **Input fixture:** Set of video files (valid mp4, invalid format, oversized, corrupt, edge cases)
- **Expected output:** `VideoIngested` event with correct metadata OR validation error
- **Test:** Submit file -> assert event payload matches expected schema and values
- **Contract test:** Consumer (Content Analysis Engine) defines expected `VideoIngested` schema via Pact

#### Content Analysis Engine
- **Input fixture:** Set of `VideoIngested` events with known video content
- **Expected output:** `ContentAnalyzed` events with expected scene/style classifications
- **Test:** Feed event -> assert analysis results match golden reference within tolerance
- **Golden dataset:** 10 reference videos with hand-labeled analysis results

#### Prompt Generation
- **Input fixture:** Set of `ContentAnalyzed` events
- **Expected output:** `PromptsGenerated` events with valid prompt strings
- **Test:** Feed analysis -> assert prompts contain expected keywords, follow template structure
- **Regression test:** Generated prompts compared against golden prompt set (fuzzy match)

#### Workflow Orchestrator
- **Input fixture:** `PromptsGenerated` events + mock Economic Engine manifest + mock Execution Engine responses
- **Expected output:** Correct fan-out, correct best-result selection, correct approval flow
- **Test:** Full saga test with mocked dependencies -- verify state machine transitions
- **Failure test:** Simulate TaskFailed for all providers -> verify compensation (user notification, refund)

#### Execution Engine
- **Input fixture:** `ExecutionTask` DTOs with known parameters
- **Expected output:** `TaskSucceeded` with correct output URIs and telemetry
- **Test:** Use mock ComfyUI API (returns canned responses) -> verify adapter translation correctness
- **Integration test:** Against real ComfyUI instance with trivial workflow (fast, cheap)

#### Quality Evaluation
- **Input fixture:** Known output videos with pre-calculated quality scores
- **Expected output:** `QualityEvaluated` events with scores within tolerance of golden reference
- **Test:** Feed known-good and known-bad outputs -> assert scoring discriminates correctly
- **Perceptual hash test:** Compare outputs against golden master using pHash distance

#### Economic Engine
- **Input fixture:** Synthetic history of `TaskSucceeded` + `QualityEvaluated` events
- **Scenario tests:**
  - Provider A consistently cheaper -> assert routing manifest favors A >90%
  - Provider B suddenly 3x cheaper -> feed new data -> assert manifest updates within cycle
  - Provider C starts failing 50% -> assert weight drops to near-zero
  - Exploration budget -> assert exactly ~5% of routing goes to non-optimal providers
- **Marginality test:** Feed known costs + known token charges -> verify marginality calculation accuracy

#### Notification & Approval
- **Input fixture:** `BestResultSelected` + `AdminApproved`/`AdminRejected` events
- **Expected output:** Correct notification dispatch, correct approval state transitions
- **Test:** Mock notification delivery -> verify correct channels, content, timing

#### Gallery & Publishing
- **Input fixture:** `AdminApproved` events with known effect data
- **Expected output:** `EffectPublished` events with correct revision IDs, CDN URLs
- **Test:** Verify immutable revision creation, publish pin correctness, CDN distribution

### Consumer-Driven Contract Testing (Pact)

Each consumer defines the contract it expects from a producer:

```
Content Analysis Engine (consumer) defines:
  "I expect VideoIngested events to have: video_id (string), normalized_uri (string starting with s3://), metadata.duration_ms (integer > 0)"

Video Intake (producer) is tested against this contract:
  "When I produce a VideoIngested event, it MUST satisfy all consumer contracts"
```

This prevents Video Intake from deploying a change that would break Content Analysis Engine.

### End-to-End Trace Testing

1. Initiate upload via API (Step 1)
2. Propagate `correlation_id` through ALL events
3. Poll Gallery for `EffectPublished` event with matching `correlation_id`
4. Query centralized logging with `correlation_id` -> verify ALL intermediate events fired in correct order
5. Verify economic telemetry was recorded correctly

---

## 10. ARCHITECTURAL DECISIONS AND TRADE-OFFS

### Decision 1: Saga Orchestrator vs Choreography
- **Chosen:** Saga Orchestrator (Workflow Orchestrator module)
- **Rationale:** The video processing pipeline is long-running (minutes), has complex failure handling (partial refunds, retry logic, compensation), and needs clear state visibility. Pure choreography would make debugging nearly impossible.
- **Trade-off:** Orchestrator is a coupling point, but its logic is purely coordination (no business logic), making it replaceable.

### Decision 2: Published Policy vs Real-Time API for Economic Engine
- **Chosen:** Published Policy (Redis manifest, updated every 15 min)
- **Rationale:** Keeps the critical execution path fast (<1ms Redis read vs synchronous API call). Economic Engine can be down without affecting job processing. Emergency overrides still possible.
- **Trade-off:** Routing decisions are up to 15 minutes stale. Mitigated by emergency override mechanism for critical anomalies.

### Decision 3: Dedicated Telemetry Stream vs Main Event Bus
- **Chosen:** Dedicated stream (separate Kafka topic)
- **Rationale:** High-volume execution telemetry (every GPU second, every node timing) would flood the main event bus. Separating keeps business events clean and telemetry processing independently scalable.
- **Trade-off:** Two infrastructure components to maintain. Mitigated by using same Kafka cluster with different topics.

### Decision 4: Quality as Vector vs Single Score
- **Chosen:** Multi-dimensional quality vector
- **Rationale:** A single score hides important trade-offs. A video might score 0.9 on style but 0.5 on temporal consistency -- the composite hides this. Vectors allow the Economic Engine to learn user-preference-weighted scoring.
- **Trade-off:** More complex comparison logic. Mitigated by also computing a composite_score for simple ranking when detailed analysis isn't needed.

### Decision 5: Epsilon-Greedy Exploration (5%)
- **Chosen:** 5% exploration rate
- **Rationale:** Without exploration, the system never gathers data on new/changed providers. 5% provides fresh data while limiting user impact.
- **Trade-off:** 5% of user jobs may get slightly worse results. Mitigated by shadow testing for completely new providers (results not shown to users).

---

## 11. ACTIONABLE NEXT STEPS

### Phase 1: Foundation (Weeks 1-3)
1. Define and formalize all DTOs as PHP classes with schema-first approach (extend existing workflow analysis DTO pattern)
2. Implement IProviderAdapter interface and first concrete adapter (SVD)
3. Set up event bus infrastructure (Redis Streams or SQS for MVP, Kafka for scale)
4. Implement Provider Registry with CRUD admin interface

### Phase 2: Economic Engine Core (Weeks 4-6)
5. Implement marginality tracker (per-task cost calculation)
6. Build routing policy manifest generator (Redis publish)
7. Implement epsilon-greedy exploration logic
8. Build Admin Panel economic dashboard (margins, provider comparison)

### Phase 3: Pipeline Integration (Weeks 7-9)
9. Implement Workflow Orchestrator saga (state machine)
10. Implement parallel test dispatch + result aggregation
11. Integrate Quality Evaluation module
12. Wire up the full user flow (Steps 1-13)

### Phase 4: Intelligence Layer (Weeks 10-12)
13. Implement Signal Detection Engine (CUSUM + Z-score)
14. Implement Decision Tree Classifier for bottleneck categories
15. Build action-oriented logging schema across all modules
16. Set up TIG stack integration + Grafana dashboards

### Phase 5: Testing and Hardening (Weeks 13-14)
17. Implement Pact consumer-driven contract tests for all module boundaries
18. Build golden dataset for E2E testing
19. Implement fault injection tests (provider failure, GPU saturation, queue overflow)
20. Load test full pipeline at 10x expected traffic
