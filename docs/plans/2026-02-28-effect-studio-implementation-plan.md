# Effect Studio — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a modular AI video effects platform where users submit example videos, the system tests across multiple providers, evaluates quality + cost + speed, selects the best result, and publishes to a gallery — with economic profitability as the #1 priority.

**Architecture:** 10 independent modules (Video Intake, Content Analysis, Provider Registry, Prompt Generation, Workflow Orchestrator, Execution Engine, Quality Evaluation, Economic Engine, Notification & Approval, Gallery & Publishing) communicating via Redis Streams events. Each module is a bounded context with defined I/O contracts, independently testable as a blackbox. The Economic Engine is the #1 priority module.

**Tech Stack:** Laravel 11 (PHP 8.3), Next.js 16 (React 19, TypeScript), Redis Streams, Recharts, Radix UI + Tailwind v4, ComfyUI GPU fleets (AWS ECS/ASG), Gemini Pro Vision (content analysis + quality evaluation).

**Reference Docs:**
- `docs/plans/effect-studio-modular-architecture-v1.md` — module contracts
- `docs/plans/2026-02-28-effect-studio-ui-ux-design.md` — UI/UX specification
- `docs/plans/effect-studio-deep-analysis-gemini.md` — deep architectural analysis

**Supersession notice (Iteration 1 authoritative plans):**
- `.cursor/plans/` is the authoritative implementation source for Iteration 1.
- Stream names are standardized as `studio:business` for business events.
- The Redis routing manifest approach documented later in this file is superseded by immutable routing bindings from `.cursor/plans/phase13_m6_routing_apply_rollback_manifest_tdd.plan.md`.
- Keep this file as reference/inspiration where it does not conflict with `.cursor/plans/`.

---

## Codebase Patterns Reference

Before implementing, understand these existing patterns:

**Models:** Extend `CentralModel` (central DB) or `TenantModel` (tenant DB). Both extend `BaseModel`. All use `$fillable`, `$casts`, `getRules()`, relationships. Namespace: `App\Models`.

**Services:** Namespace `App\Services`. Constructor DI. Use `DB::connection('tenant')->transaction()` for tenant ops. Resolve via `app(ServiceClass::class)`.

**Controllers:** Extend `BaseController` in `App\Http\Controllers\Admin`. Use `buildParamsFromRequest()`, `addSearchCriteria()`, `extractFilters()`, `addCountQueryAndExecute()` for list endpoints. Admin routes under `EnsureAdmin` middleware.

**Migrations:** Anonymous class syntax (`return new class extends Migration`). Idempotent (check column/index existence).

**Jobs:** Implement `ShouldQueue`. Use `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`. Always wrap tenant ops in `try { $tenancy->initialize($tenant); ... } finally { $tenancy->end(); }`.

**Frontend:** `"use client"` directive. Data fetching with `apiGet`/`apiPost` from `@/lib/api`. State with `useState`/`useCallback`/`useEffect`. Errors with `toast.error(extractErrorMessage(e, "msg"))`. Components use `cn()` from `@/lib/utils` for class merging. Icons from `lucide-react`.

**API client:** Functions in `frontend/src/lib/api.ts`. Pattern: `export function getX(): Promise<T> { return apiGet<T>("/admin/path", query); }`.

**No formal DTO classes exist** — codebase uses PHP arrays. We will introduce simple array-shape DTOs where contracts matter.

**No formal Event/Listener system** — jobs dispatch async work, models store event records. We will introduce Redis Streams for inter-module events.

---

## Phase 1: Foundation & Contracts

### Task 1: Create Provider Model and Migration

**Files:**
- Create: `backend/app/Models/Provider.php`
- Create: `backend/database/migrations/2026_03_01_000001_create_providers_table.php`
- Test: `backend/tests/Unit/Models/ProviderTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Models/ProviderTest.php
namespace Tests\Unit\Models;

use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_be_created_with_required_fields(): void
    {
        $provider = Provider::create([
            'name' => 'RunPod ComfyUI',
            'slug' => 'runpod-comfyui',
            'adapter_class' => 'App\\Adapters\\ComfyUiRunPodAdapter',
            'is_active' => true,
            'health_score' => 1.0,
            'config' => ['endpoint' => 'https://api.runpod.ai', 'timeout' => 120],
            'capabilities' => ['image_to_video', 'style_transfer'],
            'supported_effect_types' => ['upscale', 'style', 'animate'],
        ]);

        $this->assertDatabaseHas('providers', ['slug' => 'runpod-comfyui']);
        $this->assertEquals(['image_to_video', 'style_transfer'], $provider->capabilities);
        $this->assertTrue($provider->is_active);
    }

    public function test_provider_health_score_defaults_to_one(): void
    {
        $provider = Provider::create([
            'name' => 'Test',
            'slug' => 'test',
            'adapter_class' => 'App\\Adapters\\TestAdapter',
        ]);

        $this->assertEquals(1.0, $provider->health_score);
    }

    public function test_provider_slug_is_unique(): void
    {
        Provider::create(['name' => 'A', 'slug' => 'same', 'adapter_class' => 'X']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Provider::create(['name' => 'B', 'slug' => 'same', 'adapter_class' => 'Y']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=ProviderTest`
Expected: FAIL — table `providers` does not exist

**Step 3: Write the migration**

```php
<?php
// backend/database/migrations/2026_03_01_000001_create_providers_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('adapter_class');
            $table->boolean('is_active')->default(true);
            $table->float('health_score')->default(1.0);
            $table->json('config')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('supported_effect_types')->nullable();
            $table->json('cost_defaults')->nullable();
            $table->float('routing_weight')->default(1.0);
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
```

**Step 4: Write the model**

```php
<?php
// backend/app/Models/Provider.php
namespace App\Models;

class Provider extends CentralModel
{
    protected $fillable = [
        'name', 'slug', 'adapter_class', 'is_active', 'health_score',
        'config', 'capabilities', 'supported_effect_types', 'cost_defaults',
        'routing_weight', 'last_health_check_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'health_score' => 'float',
        'routing_weight' => 'float',
        'config' => 'array',
        'capabilities' => 'array',
        'supported_effect_types' => 'array',
        'cost_defaults' => 'array',
        'last_health_check_at' => 'datetime',
    ];

    public static function getRules($id = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:providers,slug' . ($id ? ",$id" : ''),
            'adapter_class' => 'required|string|max:500',
            'is_active' => 'boolean',
            'health_score' => 'numeric|min:0|max:1',
            'config' => 'nullable|array',
            'capabilities' => 'nullable|array',
            'supported_effect_types' => 'nullable|array',
            'routing_weight' => 'numeric|min:0|max:1',
        ];
    }

    public function supportsEffectType(string $effectType): bool
    {
        return in_array($effectType, $this->supported_effect_types ?? [], true);
    }

    public function isHealthy(): bool
    {
        return $this->is_active && $this->health_score >= 0.5;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeHealthy($query)
    {
        return $query->where('is_active', true)->where('health_score', '>=', 0.5);
    }

    public function scopeForEffectType($query, string $effectType)
    {
        return $query->whereJsonContains('supported_effect_types', $effectType);
    }
}
```

**Step 5: Run migration and test**

Run: `cd backend && php artisan migrate && php artisan test --filter=ProviderTest`
Expected: 3 tests PASS

**Step 6: Commit**

```bash
git add backend/app/Models/Provider.php backend/database/migrations/2026_03_01_000001_create_providers_table.php backend/tests/Unit/Models/ProviderTest.php
git commit -m "feat(M3): add Provider model with capabilities and health tracking"
```

---

### Task 2: Create IProviderAdapter Contract

**Files:**
- Create: `backend/app/Contracts/IProviderAdapter.php`
- Create: `backend/app/Adapters/ComfyUiSelfHostedAdapter.php`
- Test: `backend/tests/Unit/Adapters/ComfyUiSelfHostedAdapterTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Adapters/ComfyUiSelfHostedAdapterTest.php
namespace Tests\Unit\Adapters;

use App\Adapters\ComfyUiSelfHostedAdapter;
use App\Contracts\IProviderAdapter;
use Tests\TestCase;

class ComfyUiSelfHostedAdapterTest extends TestCase
{
    public function test_adapter_implements_interface(): void
    {
        $adapter = new ComfyUiSelfHostedAdapter([
            'endpoint' => 'http://localhost:8188',
        ]);
        $this->assertInstanceOf(IProviderAdapter::class, $adapter);
    }

    public function test_adapter_reports_supported_effect_types(): void
    {
        $adapter = new ComfyUiSelfHostedAdapter([]);
        $supported = $adapter->supportedEffectTypes();
        $this->assertIsArray($supported);
        $this->assertNotEmpty($supported);
    }

    public function test_adapter_translates_task_to_payload(): void
    {
        $adapter = new ComfyUiSelfHostedAdapter(['endpoint' => 'http://localhost:8188']);

        $task = [
            'prompt_text' => 'anime style glow effect',
            'effect_type' => 'style_transfer',
            'input_video_uri' => 's3://bucket/input.mp4',
            'parameters' => ['steps' => 20, 'cfg_scale' => 7.0],
            'workflow_id' => 1,
        ];

        $payload = $adapter->translateToProviderPayload($task);
        $this->assertArrayHasKey('workflow', $payload);
        $this->assertArrayHasKey('output_node_id', $payload);
    }

    public function test_health_check_returns_structured_result(): void
    {
        $adapter = new ComfyUiSelfHostedAdapter(['endpoint' => 'http://nonexistent:8188']);
        $health = $adapter->healthCheck();
        $this->assertArrayHasKey('healthy', $health);
        $this->assertArrayHasKey('latency_ms', $health);
        $this->assertArrayHasKey('error', $health);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=ComfyUiSelfHostedAdapterTest`
Expected: FAIL — class not found

**Step 3: Create the interface**

```php
<?php
// backend/app/Contracts/IProviderAdapter.php
namespace App\Contracts;

interface IProviderAdapter
{
    /**
     * Translate a standardized execution task into provider-specific payload.
     *
     * @param array{
     *   prompt_text: string,
     *   effect_type: string,
     *   input_video_uri: string,
     *   parameters: array<string, mixed>,
     *   workflow_id: int
     * } $task
     * @return array Provider-specific payload (e.g. ComfyUI workflow JSON)
     */
    public function translateToProviderPayload(array $task): array;

    /**
     * @return string[] List of effect types this adapter can handle
     */
    public function supportedEffectTypes(): array;

    /**
     * Run a health check against the provider.
     *
     * @return array{healthy: bool, latency_ms: float|null, error: string|null}
     */
    public function healthCheck(): array;

    /**
     * Get the provider identifier for this adapter.
     */
    public function providerSlug(): string;
}
```

**Step 4: Create the first concrete adapter**

```php
<?php
// backend/app/Adapters/ComfyUiSelfHostedAdapter.php
namespace App\Adapters;

use App\Contracts\IProviderAdapter;

class ComfyUiSelfHostedAdapter implements IProviderAdapter
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function translateToProviderPayload(array $task): array
    {
        // Delegates to WorkflowPayloadService for the actual workflow JSON building.
        // This adapter's job is to wrap it in the self-hosted ComfyUI format.
        return [
            'workflow' => $task['parameters']['workflow_json'] ?? [],
            'output_node_id' => $task['parameters']['output_node_id'] ?? '',
            'output_extension' => $task['parameters']['output_extension'] ?? 'mp4',
            'output_mime_type' => $task['parameters']['output_mime_type'] ?? 'video/mp4',
            'input_path_placeholder' => $task['parameters']['input_path_placeholder'] ?? '',
            'prompt_text' => $task['prompt_text'],
            'effect_type' => $task['effect_type'],
        ];
    }

    public function supportedEffectTypes(): array
    {
        return ['style_transfer', 'upscale', 'animate', 'img2vid', 'vid2vid'];
    }

    public function healthCheck(): array
    {
        $endpoint = $this->config['endpoint'] ?? '';
        if (!$endpoint) {
            return ['healthy' => false, 'latency_ms' => null, 'error' => 'No endpoint configured'];
        }

        $start = microtime(true);
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $result = @file_get_contents("{$endpoint}/system_stats", false, $ctx);
            $latency = (microtime(true) - $start) * 1000;
            return [
                'healthy' => $result !== false,
                'latency_ms' => round($latency, 1),
                'error' => $result === false ? 'Connection failed' : null,
            ];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'latency_ms' => null, 'error' => $e->getMessage()];
        }
    }

    public function providerSlug(): string
    {
        return 'comfyui-self-hosted';
    }
}
```

**Step 5: Run tests**

Run: `cd backend && php artisan test --filter=ComfyUiSelfHostedAdapterTest`
Expected: 4 tests PASS

**Step 6: Commit**

```bash
git add backend/app/Contracts/IProviderAdapter.php backend/app/Adapters/ComfyUiSelfHostedAdapter.php backend/tests/Unit/Adapters/ComfyUiSelfHostedAdapterTest.php
git commit -m "feat(M6): add IProviderAdapter contract and ComfyUiSelfHostedAdapter"
```

---

### Task 3: Create Redis Streams Event Infrastructure

**Files:**
- Create: `backend/app/Services/EventBus/RedisStreamPublisher.php`
- Create: `backend/app/Services/EventBus/RedisStreamConsumer.php`
- Create: `backend/config/streams.php`
- Test: `backend/tests/Unit/Services/EventBus/RedisStreamPublisherTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Services/EventBus/RedisStreamPublisherTest.php
namespace Tests\Unit\Services\EventBus;

use App\Services\EventBus\RedisStreamPublisher;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisStreamPublisherTest extends TestCase
{
    public function test_publishes_event_to_correct_stream(): void
    {
        Redis::shouldReceive('xadd')
            ->once()
            ->withArgs(function ($stream, $id, $fields) {
                return $stream === 'studio:business'
                    && $id === '*'
                    && json_decode($fields['payload'], true)['video_id'] === 42;
            })
            ->andReturn('1234567890-0');

        $publisher = new RedisStreamPublisher();
        $messageId = $publisher->publish('business_events', 'VideoIngested', [
            'video_id' => 42,
            'user_id' => 1,
            'source_uri' => 's3://bucket/video.mp4',
        ]);

        $this->assertNotNull($messageId);
    }

    public function test_publishes_telemetry_to_separate_stream(): void
    {
        Redis::shouldReceive('xadd')
            ->once()
            ->withArgs(function ($stream) {
                return $stream === 'es:economic_telemetry';
            })
            ->andReturn('1234567890-1');

        $publisher = new RedisStreamPublisher();
        $publisher->publish('economic_telemetry', 'ExecutionCostRecorded', [
            'dispatch_id' => 99,
            'cost_usd' => 0.045,
        ]);
    }

    public function test_event_envelope_contains_required_fields(): void
    {
        Redis::shouldReceive('xadd')
            ->once()
            ->withArgs(function ($stream, $id, $fields) {
                return isset($fields['event_type'])
                    && isset($fields['payload'])
                    && isset($fields['timestamp'])
                    && isset($fields['correlation_id']);
            })
            ->andReturn('1234567890-2');

        $publisher = new RedisStreamPublisher();
        $publisher->publish('business_events', 'TestEvent', ['key' => 'val'], 'corr-123');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=RedisStreamPublisherTest`
Expected: FAIL — class not found

**Step 3: Create config file**

```php
<?php
// backend/config/streams.php
return [
    'prefix' => env('REDIS_STREAM_PREFIX', 'es:'),
    'streams' => [
        'business_events' => [
            'max_length' => env('STREAM_BUSINESS_MAXLEN', 100000),
            'consumer_group' => 'effect_studio',
        ],
        'economic_telemetry' => [
            'max_length' => env('STREAM_TELEMETRY_MAXLEN', 500000),
            'consumer_group' => 'economic_engine',
        ],
    ],
];
```

**Step 4: Create publisher**

```php
<?php
// backend/app/Services/EventBus/RedisStreamPublisher.php
namespace App\Services\EventBus;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class RedisStreamPublisher
{
    public function publish(
        string $stream,
        string $eventType,
        array $payload,
        ?string $correlationId = null,
    ): string {
        $prefix = config('streams.prefix', 'es:');
        $streamKey = $prefix . $stream;
        $maxLen = config("streams.streams.{$stream}.max_length", 100000);

        $fields = [
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'timestamp' => now()->toIso8601String(),
            'correlation_id' => $correlationId ?? Str::uuid()->toString(),
        ];

        return Redis::xadd($streamKey, '*', $fields);
    }
}
```

**Step 5: Create consumer**

```php
<?php
// backend/app/Services/EventBus/RedisStreamConsumer.php
namespace App\Services\EventBus;

use Illuminate\Support\Facades\Redis;

class RedisStreamConsumer
{
    public function __construct(
        private readonly string $stream,
        private readonly string $group,
        private readonly string $consumer,
    ) {}

    /**
     * Read pending messages from the stream consumer group.
     *
     * @return array<int, array{id: string, event_type: string, payload: array, timestamp: string, correlation_id: string}>
     */
    public function read(int $count = 10, int $blockMs = 2000): array
    {
        $prefix = config('streams.prefix', 'es:');
        $streamKey = $prefix . $this->stream;

        $this->ensureGroup($streamKey);

        $results = Redis::xreadgroup(
            $this->group,
            $this->consumer,
            [$streamKey => '>'],
            $count,
            $blockMs,
        );

        if (!$results) {
            return [];
        }

        $messages = [];
        foreach ($results[$streamKey] ?? [] as $id => $fields) {
            $messages[] = [
                'id' => $id,
                'event_type' => $fields['event_type'] ?? '',
                'payload' => json_decode($fields['payload'] ?? '{}', true),
                'timestamp' => $fields['timestamp'] ?? '',
                'correlation_id' => $fields['correlation_id'] ?? '',
            ];
        }

        return $messages;
    }

    public function acknowledge(string $messageId): void
    {
        $prefix = config('streams.prefix', 'es:');
        $streamKey = $prefix . $this->stream;
        Redis::xack($streamKey, $this->group, [$messageId]);
    }

    private function ensureGroup(string $streamKey): void
    {
        try {
            Redis::xgroup('CREATE', $streamKey, $this->group, '0', true);
        } catch (\Throwable) {
            // Group already exists — ignore
        }
    }
}
```

**Step 6: Run tests**

Run: `cd backend && php artisan test --filter=RedisStreamPublisherTest`
Expected: 3 tests PASS

**Step 7: Commit**

```bash
git add backend/app/Services/EventBus/ backend/config/streams.php backend/tests/Unit/Services/EventBus/
git commit -m "feat(infra): add Redis Streams event bus with publisher and consumer"
```

---

### Task 4: Create OrchestrationJob Model (Saga State Machine)

**Files:**
- Create: `backend/app/Models/OrchestrationJob.php`
- Create: `backend/database/migrations/2026_03_01_000002_create_orchestration_jobs_table.php`
- Test: `backend/tests/Unit/Models/OrchestrationJobTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Models/OrchestrationJobTest.php
namespace Tests\Unit\Models;

use App\Models\OrchestrationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrchestrationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_orchestration_job_tracks_pipeline_stages(): void
    {
        $job = OrchestrationJob::create([
            'user_id' => 1,
            'correlation_id' => 'test-corr-123',
            'stage' => 'video_ingested',
            'status' => 'in_progress',
            'input_video_uri' => 's3://bucket/input.mp4',
            'user_description' => 'anime glow effect',
        ]);

        $this->assertEquals('video_ingested', $job->stage);
        $this->assertEquals('in_progress', $job->status);
    }

    public function test_stage_transitions_are_valid(): void
    {
        $job = OrchestrationJob::create([
            'user_id' => 1,
            'correlation_id' => 'test-corr-456',
            'stage' => 'video_ingested',
            'status' => 'in_progress',
        ]);

        $this->assertTrue($job->canTransitionTo('content_analyzed'));
        $this->assertFalse($job->canTransitionTo('published'));  // Can't skip stages
    }

    public function test_records_per_stage_cost_and_duration(): void
    {
        $job = OrchestrationJob::create([
            'user_id' => 1,
            'correlation_id' => 'test-corr-789',
            'stage' => 'content_analyzed',
            'status' => 'in_progress',
            'stage_costs' => ['video_ingested' => 5, 'content_analyzed' => 3],
            'stage_durations_ms' => ['video_ingested' => 12000, 'content_analyzed' => 8000],
        ]);

        $this->assertEquals(8, $job->totalTokensConsumed());
        $this->assertEquals(3, $job->stage_costs['content_analyzed']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=OrchestrationJobTest`
Expected: FAIL

**Step 3: Write migration**

```php
<?php
// backend/database/migrations/2026_03_01_000002_create_orchestration_jobs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orchestration_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained();
            $table->string('correlation_id')->unique();
            $table->string('stage', 50)->default('submitted')->index();
            $table->string('status', 30)->default('pending')->index();
            $table->string('input_video_uri', 1024)->nullable();
            $table->text('user_description')->nullable();
            $table->json('style_preferences')->nullable();
            $table->json('content_analysis')->nullable();
            $table->json('generated_prompts')->nullable();
            $table->json('provider_results')->nullable();
            $table->json('quality_scores')->nullable();
            $table->json('stage_costs')->nullable();
            $table->json('stage_durations_ms')->nullable();
            $table->integer('selected_provider_id')->nullable();
            $table->integer('selected_dispatch_id')->nullable();
            $table->integer('total_tokens_reserved')->default(0);
            $table->integer('total_tokens_consumed')->default(0);
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestration_jobs');
    }
};
```

**Step 4: Write the model**

```php
<?php
// backend/app/Models/OrchestrationJob.php
namespace App\Models;

class OrchestrationJob extends CentralModel
{
    public const STAGES = [
        'submitted',
        'video_ingested',
        'content_analyzed',
        'prompts_generated',
        'testing_providers',
        'quality_evaluated',
        'best_selected',
        'awaiting_approval',
        'approved',
        'published',
    ];

    public const STATUSES = ['pending', 'in_progress', 'completed', 'failed', 'cancelled'];

    protected $fillable = [
        'user_id', 'correlation_id', 'stage', 'status',
        'input_video_uri', 'user_description', 'style_preferences',
        'content_analysis', 'generated_prompts', 'provider_results',
        'quality_scores', 'stage_costs', 'stage_durations_ms',
        'selected_provider_id', 'selected_dispatch_id',
        'total_tokens_reserved', 'total_tokens_consumed',
        'failure_reason', 'completed_at',
    ];

    protected $casts = [
        'style_preferences' => 'array',
        'content_analysis' => 'array',
        'generated_prompts' => 'array',
        'provider_results' => 'array',
        'quality_scores' => 'array',
        'stage_costs' => 'array',
        'stage_durations_ms' => 'array',
        'total_tokens_reserved' => 'integer',
        'total_tokens_consumed' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function selectedProvider()
    {
        return $this->belongsTo(Provider::class, 'selected_provider_id');
    }

    public function canTransitionTo(string $targetStage): bool
    {
        $currentIndex = array_search($this->stage, self::STAGES, true);
        $targetIndex = array_search($targetStage, self::STAGES, true);

        if ($currentIndex === false || $targetIndex === false) {
            return false;
        }

        return $targetIndex === $currentIndex + 1;
    }

    public function transitionTo(string $stage): void
    {
        if (!$this->canTransitionTo($stage)) {
            throw new \RuntimeException(
                "Cannot transition from '{$this->stage}' to '{$stage}'"
            );
        }
        $this->stage = $stage;
        $this->save();
    }

    public function totalTokensConsumed(): int
    {
        return array_sum($this->stage_costs ?? []);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'in_progress']);
    }
}
```

**Step 5: Run migration and tests**

Run: `cd backend && php artisan migrate && php artisan test --filter=OrchestrationJobTest`
Expected: 3 tests PASS

**Step 6: Commit**

```bash
git add backend/app/Models/OrchestrationJob.php backend/database/migrations/2026_03_01_000002_create_orchestration_jobs_table.php backend/tests/Unit/Models/OrchestrationJobTest.php
git commit -m "feat(M5): add OrchestrationJob saga model with stage state machine"
```

---

### Task 5: Create QualityScore Model

**Files:**
- Create: `backend/app/Models/QualityScore.php`
- Create: `backend/database/migrations/2026_03_01_000003_create_quality_scores_table.php`
- Test: `backend/tests/Unit/Models/QualityScoreTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Models/QualityScoreTest.php
namespace Tests\Unit\Models;

use App\Models\QualityScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_score_stores_multi_dimensional_vector(): void
    {
        $score = QualityScore::create([
            'orchestration_job_id' => 1,
            'provider_id' => 2,
            'dispatch_id' => 10,
            'fidelity' => 0.87,
            'artifacts' => 0.92,
            'style_adherence' => 0.78,
            'temporal_consistency' => 0.85,
        ]);

        $this->assertEqualsWithDelta(0.855, $score->compositeScore(), 0.001);
    }

    public function test_composite_score_is_weighted_average(): void
    {
        $score = QualityScore::create([
            'orchestration_job_id' => 1,
            'provider_id' => 1,
            'dispatch_id' => 5,
            'fidelity' => 1.0,
            'artifacts' => 1.0,
            'style_adherence' => 1.0,
            'temporal_consistency' => 1.0,
        ]);

        $this->assertEquals(1.0, $score->compositeScore());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=QualityScoreTest`
Expected: FAIL

**Step 3: Write migration**

```php
<?php
// backend/database/migrations/2026_03_01_000003_create_quality_scores_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quality_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orchestration_job_id')->index()->constrained();
            $table->unsignedBigInteger('provider_id')->index();
            $table->unsignedBigInteger('dispatch_id')->nullable()->index();
            $table->float('fidelity')->default(0);
            $table->float('artifacts')->default(0);
            $table->float('style_adherence')->default(0);
            $table->float('temporal_consistency')->default(0);
            $table->float('composite_score')->nullable();
            $table->json('raw_evaluation')->nullable();
            $table->string('evaluation_model', 100)->nullable();
            $table->integer('evaluation_duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_scores');
    }
};
```

**Step 4: Write the model**

```php
<?php
// backend/app/Models/QualityScore.php
namespace App\Models;

class QualityScore extends CentralModel
{
    public const WEIGHTS = [
        'fidelity' => 0.30,
        'artifacts' => 0.25,
        'style_adherence' => 0.25,
        'temporal_consistency' => 0.20,
    ];

    protected $fillable = [
        'orchestration_job_id', 'provider_id', 'dispatch_id',
        'fidelity', 'artifacts', 'style_adherence', 'temporal_consistency',
        'composite_score', 'raw_evaluation', 'evaluation_model', 'evaluation_duration_ms',
    ];

    protected $casts = [
        'fidelity' => 'float',
        'artifacts' => 'float',
        'style_adherence' => 'float',
        'temporal_consistency' => 'float',
        'composite_score' => 'float',
        'raw_evaluation' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (QualityScore $score) {
            $score->composite_score = $score->compositeScore();
        });
    }

    public function compositeScore(): float
    {
        $sum = 0;
        foreach (self::WEIGHTS as $dim => $weight) {
            $sum += ($this->{$dim} ?? 0) * $weight;
        }
        return round($sum, 4);
    }

    public function qualityVector(): array
    {
        return [
            'fidelity' => $this->fidelity,
            'artifacts' => $this->artifacts,
            'style_adherence' => $this->style_adherence,
            'temporal_consistency' => $this->temporal_consistency,
        ];
    }

    public function orchestrationJob()
    {
        return $this->belongsTo(OrchestrationJob::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
```

**Step 5: Run migration and tests**

Run: `cd backend && php artisan migrate && php artisan test --filter=QualityScoreTest`
Expected: 2 tests PASS

**Step 6: Commit**

```bash
git add backend/app/Models/QualityScore.php backend/database/migrations/2026_03_01_000003_create_quality_scores_table.php backend/tests/Unit/Models/QualityScoreTest.php
git commit -m "feat(M7): add QualityScore model with multi-dimensional quality vector"
```

---

### Task 6: Create EconomicRecommendation Model

**Files:**
- Create: `backend/app/Models/EconomicRecommendation.php`
- Create: `backend/database/migrations/2026_03_01_000004_create_economic_recommendations_table.php`
- Test: `backend/tests/Unit/Models/EconomicRecommendationTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Models/EconomicRecommendationTest.php
namespace Tests\Unit\Models;

use App\Models\EconomicRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EconomicRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendation_types_are_valid(): void
    {
        $rec = EconomicRecommendation::create([
            'type' => 'PROVIDER_SWITCH',
            'title' => 'Switch effect X from provider A to B',
            'description' => 'Provider B offers 26% better margin with 3% quality reduction.',
            'confidence' => 0.87,
            'impact_usd_per_day' => 12.50,
            'based_on_executions' => 150,
            'status' => 'pending',
            'details' => [
                'from_provider_id' => 1,
                'to_provider_id' => 2,
                'margin_change' => 0.26,
                'quality_change' => -0.03,
            ],
        ]);

        $this->assertEquals('PROVIDER_SWITCH', $rec->type);
        $this->assertEqualsWithDelta(12.50, $rec->impact_usd_per_day, 0.01);
    }

    public function test_pending_scope(): void
    {
        EconomicRecommendation::create([
            'type' => 'PRICE_ADJUSTMENT', 'title' => 'T', 'status' => 'pending',
            'confidence' => 0.5, 'impact_usd_per_day' => 1, 'based_on_executions' => 10,
        ]);
        EconomicRecommendation::create([
            'type' => 'PRICE_ADJUSTMENT', 'title' => 'T2', 'status' => 'approved',
            'confidence' => 0.5, 'impact_usd_per_day' => 1, 'based_on_executions' => 10,
        ]);

        $this->assertCount(1, EconomicRecommendation::pending()->get());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=EconomicRecommendationTest`
Expected: FAIL

**Step 3: Write migration**

```php
<?php
// backend/database/migrations/2026_03_01_000004_create_economic_recommendations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('economic_recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->float('confidence');
            $table->float('impact_usd_per_day');
            $table->integer('based_on_executions')->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->json('details')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('economic_recommendations');
    }
};
```

**Step 4: Write the model**

```php
<?php
// backend/app/Models/EconomicRecommendation.php
namespace App\Models;

class EconomicRecommendation extends CentralModel
{
    public const TYPES = [
        'PROVIDER_SWITCH',
        'PRICE_ADJUSTMENT',
        'FLEET_OPTIMIZATION',
        'WORKFLOW_TUNING',
    ];

    protected $fillable = [
        'type', 'title', 'description', 'confidence', 'impact_usd_per_day',
        'based_on_executions', 'status', 'details',
        'approved_at', 'rejected_at', 'approved_by', 'rejection_reason',
    ];

    protected $casts = [
        'confidence' => 'float',
        'impact_usd_per_day' => 'float',
        'based_on_executions' => 'integer',
        'details' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function approve(int $userId): void
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $userId,
        ]);
    }

    public function reject(int $userId, ?string $reason = null): void
    {
        $this->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'approved_by' => $userId,
            'rejection_reason' => $reason,
        ]);
    }
}
```

**Step 5: Run migration and tests**

Run: `cd backend && php artisan migrate && php artisan test --filter=EconomicRecommendationTest`
Expected: 2 tests PASS

**Step 6: Commit**

```bash
git add backend/app/Models/EconomicRecommendation.php backend/database/migrations/2026_03_01_000004_create_economic_recommendations_table.php backend/tests/Unit/Models/EconomicRecommendationTest.php
git commit -m "feat(M8): add EconomicRecommendation model with typed recommendations"
```

---

### Task 7: Create BottleneckClassification Model

**Files:**
- Create: `backend/app/Models/BottleneckClassification.php`
- Create: `backend/database/migrations/2026_03_01_000005_create_bottleneck_classifications_table.php`
- Test: `backend/tests/Unit/Models/BottleneckClassificationTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Models/BottleneckClassificationTest.php
namespace Tests\Unit\Models;

use App\Models\BottleneckClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BottleneckClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bottleneck_stores_classification_with_action(): void
    {
        $bn = BottleneckClassification::create([
            'category' => 'GPU_SATURATION',
            'severity' => 'HIGH',
            'confidence' => 0.92,
            'is_active' => true,
            'provider_id' => 1,
            'description' => 'GPU fleet at 95% capacity for 15 minutes',
            'recommended_action' => 'Scale up ASG',
            'auto_action_taken' => 'Increased desired capacity from 3 to 5',
            'estimated_cost_impact_usd' => 2.40,
            'affected_jobs_count' => 15,
        ]);

        $this->assertTrue($bn->is_active);
        $this->assertEquals('GPU_SATURATION', $bn->category);
    }

    public function test_active_scope(): void
    {
        BottleneckClassification::create([
            'category' => 'GPU_SATURATION', 'severity' => 'HIGH',
            'confidence' => 0.9, 'is_active' => true,
        ]);
        BottleneckClassification::create([
            'category' => 'COLD_START_PENALTY', 'severity' => 'LOW',
            'confidence' => 0.8, 'is_active' => false,
        ]);

        $this->assertCount(1, BottleneckClassification::active()->get());
    }
}
```

**Step 2-6: Follow same pattern as Task 6**

Migration:
```php
<?php
// backend/database/migrations/2026_03_01_000005_create_bottleneck_classifications_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bottleneck_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50)->index();
            $table->string('severity', 20)->index();
            $table->float('confidence');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('provider_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('recommended_action', 500)->nullable();
            $table->string('auto_action_taken', 500)->nullable();
            $table->float('estimated_cost_impact_usd')->nullable();
            $table->integer('affected_jobs_count')->default(0);
            $table->json('signal_data')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bottleneck_classifications');
    }
};
```

Model:
```php
<?php
// backend/app/Models/BottleneckClassification.php
namespace App\Models;

class BottleneckClassification extends CentralModel
{
    public const CATEGORIES = [
        'GPU_SATURATION',
        'PROVIDER_LATENCY_DEGRADATION',
        'PROVIDER_API_THROTTLING',
        'TOKEN_DEPLETION_RISK',
        'WORKFLOW_INEFFICIENCY',
        'COLD_START_PENALTY',
    ];

    public const SEVERITIES = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'];

    protected $fillable = [
        'category', 'severity', 'confidence', 'is_active', 'provider_id',
        'description', 'recommended_action', 'auto_action_taken',
        'estimated_cost_impact_usd', 'affected_jobs_count',
        'signal_data', 'resolved_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'is_active' => 'boolean',
        'estimated_cost_impact_usd' => 'float',
        'affected_jobs_count' => 'integer',
        'signal_data' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function resolve(): void
    {
        $this->update(['is_active' => false, 'resolved_at' => now()]);
    }
}
```

Run: `cd backend && php artisan migrate && php artisan test --filter=BottleneckClassificationTest`
Expected: 2 tests PASS

Commit:
```bash
git add backend/app/Models/BottleneckClassification.php backend/database/migrations/2026_03_01_000005_create_bottleneck_classifications_table.php backend/tests/Unit/Models/BottleneckClassificationTest.php
git commit -m "feat(M8): add BottleneckClassification model with 6-category taxonomy"
```

---

### Task 8: Create Notification and ApprovalQueueEntry Models

**Files:**
- Create: `backend/app/Models/UserNotification.php`
- Create: `backend/app/Models/ApprovalQueueEntry.php`
- Create: `backend/database/migrations/2026_03_01_000006_create_notifications_and_approval_queue_tables.php`
- Test: `backend/tests/Unit/Models/UserNotificationTest.php`
- Test: `backend/tests/Unit/Models/ApprovalQueueEntryTest.php`

**Migration:**

```php
<?php
// backend/database/migrations/2026_03_01_000006_create_notifications_and_approval_queue_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained();
            $table->string('type', 50)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('approval_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orchestration_job_id')->index()->constrained();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_queue_entries');
        Schema::dropIfExists('user_notifications');
    }
};
```

**Models:**

```php
<?php
// backend/app/Models/UserNotification.php
namespace App\Models;

class UserNotification extends CentralModel
{
    public const TYPES = [
        'best_result_selected',
        'effect_published',
        'admin_rejected',
        'token_alert',
    ];

    protected $fillable = ['user_id', 'type', 'title', 'body', 'data', 'is_read'];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
```

```php
<?php
// backend/app/Models/ApprovalQueueEntry.php
namespace App\Models;

class ApprovalQueueEntry extends CentralModel
{
    protected $fillable = [
        'orchestration_job_id', 'status', 'reviewed_by', 'feedback', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function orchestrationJob()
    {
        return $this->belongsTo(OrchestrationJob::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
```

Run: `cd backend && php artisan migrate && php artisan test --filter=UserNotificationTest --filter=ApprovalQueueEntryTest`

Commit:
```bash
git add backend/app/Models/UserNotification.php backend/app/Models/ApprovalQueueEntry.php backend/database/migrations/2026_03_01_000006_create_notifications_and_approval_queue_tables.php backend/tests/Unit/Models/
git commit -m "feat(M9): add UserNotification and ApprovalQueueEntry models"
```

---

## Phase 2: Economic Engine Core (TOP PRIORITY)

### Task 9: Create MarginalityTrackerService

**Files:**
- Create: `backend/app/Services/MarginalityTrackerService.php`
- Test: `backend/tests/Unit/Services/MarginalityTrackerServiceTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Services/MarginalityTrackerServiceTest.php
namespace Tests\Unit\Services;

use App\Services\MarginalityTrackerService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class MarginalityTrackerServiceTest extends TestCase
{
    public function test_calculates_margin_from_execution_data(): void
    {
        $service = new MarginalityTrackerService();

        // MARGIN = (tokens_charged - actual_cost_in_tokens) / tokens_charged
        $margin = $service->calculateMargin(
            tokensCharged: 100,
            actualCostInTokens: 60
        );

        $this->assertEqualsWithDelta(0.40, $margin, 0.001); // 40% margin
    }

    public function test_margin_with_zero_revenue_returns_negative_one(): void
    {
        $service = new MarginalityTrackerService();
        $margin = $service->calculateMargin(tokensCharged: 0, actualCostInTokens: 50);
        $this->assertEquals(-1.0, $margin);
    }

    public function test_records_execution_to_sliding_window(): void
    {
        Redis::shouldReceive('zadd')
            ->once()
            ->withArgs(function ($key, $score, $member) {
                return str_starts_with($key, 'es:marginality:')
                    && is_numeric($score);
            });

        // Trim old entries
        Redis::shouldReceive('zremrangebyscore')->once();

        $service = new MarginalityTrackerService();
        $service->recordExecution(
            providerId: 1,
            effectType: 'style_transfer',
            tokensCharged: 100,
            actualCostInTokens: 60,
            durationMs: 5000,
        );
    }

    public function test_get_margin_for_window(): void
    {
        Redis::shouldReceive('zrangebyscore')
            ->once()
            ->andReturn([
                json_encode(['tokens_charged' => 100, 'cost_tokens' => 60]),
                json_encode(['tokens_charged' => 200, 'cost_tokens' => 140]),
            ]);

        $service = new MarginalityTrackerService();
        $result = $service->getMarginForWindow(
            providerId: 1,
            windowMinutes: 60
        );

        // Total charged: 300, total cost: 200 → margin: (300-200)/300 = 0.333
        $this->assertEqualsWithDelta(0.333, $result['margin'], 0.001);
        $this->assertEquals(2, $result['execution_count']);
        $this->assertEquals(300, $result['total_tokens_charged']);
    }

    public function test_get_blended_margin_across_all_providers(): void
    {
        // Provider 1: margin 0.4
        Redis::shouldReceive('keys')
            ->andReturn(['es:marginality:provider:1', 'es:marginality:provider:2']);
        Redis::shouldReceive('zrangebyscore')
            ->andReturn([
                json_encode(['tokens_charged' => 100, 'cost_tokens' => 60]),
            ], [
                json_encode(['tokens_charged' => 100, 'cost_tokens' => 80]),
            ]);

        $service = new MarginalityTrackerService();
        $result = $service->getBlendedMargin(windowMinutes: 60);

        // Provider 1: 40% on 100 tokens, Provider 2: 20% on 100 tokens
        // Blended: (200 - 140) / 200 = 0.30
        $this->assertEqualsWithDelta(0.30, $result['blended_margin'], 0.001);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=MarginalityTrackerServiceTest`
Expected: FAIL

**Step 3: Write the service**

```php
<?php
// backend/app/Services/MarginalityTrackerService.php
namespace App\Services;

use Illuminate\Support\Facades\Redis;

class MarginalityTrackerService
{
    private const KEY_PREFIX = 'es:marginality:';
    private const WINDOWS = [
        '1h' => 60,
        '24h' => 1440,
        '7d' => 10080,
    ];

    public function calculateMargin(int $tokensCharged, int $actualCostInTokens): float
    {
        if ($tokensCharged <= 0) {
            return -1.0;
        }
        return round(($tokensCharged - $actualCostInTokens) / $tokensCharged, 4);
    }

    public function recordExecution(
        int $providerId,
        string $effectType,
        int $tokensCharged,
        int $actualCostInTokens,
        int $durationMs,
    ): void {
        $now = now()->getTimestampMs();
        $entry = json_encode([
            'tokens_charged' => $tokensCharged,
            'cost_tokens' => $actualCostInTokens,
            'duration_ms' => $durationMs,
            'effect_type' => $effectType,
            'ts' => $now,
        ]);

        $key = self::KEY_PREFIX . "provider:{$providerId}";
        Redis::zadd($key, $now, $entry);

        // Trim entries older than 7 days (max window)
        $cutoff = $now - (self::WINDOWS['7d'] * 60 * 1000);
        Redis::zremrangebyscore($key, '-inf', $cutoff);
    }

    public function getMarginForWindow(int $providerId, int $windowMinutes): array
    {
        $now = now()->getTimestampMs();
        $cutoff = $now - ($windowMinutes * 60 * 1000);
        $key = self::KEY_PREFIX . "provider:{$providerId}";

        $entries = Redis::zrangebyscore($key, $cutoff, '+inf');

        $totalCharged = 0;
        $totalCost = 0;
        $totalDuration = 0;

        foreach ($entries as $raw) {
            $entry = json_decode($raw, true);
            $totalCharged += $entry['tokens_charged'] ?? 0;
            $totalCost += $entry['cost_tokens'] ?? 0;
            $totalDuration += $entry['duration_ms'] ?? 0;
        }

        $count = count($entries);
        $margin = $this->calculateMargin($totalCharged, $totalCost);

        return [
            'margin' => $margin,
            'execution_count' => $count,
            'total_tokens_charged' => $totalCharged,
            'total_cost_tokens' => $totalCost,
            'avg_duration_ms' => $count > 0 ? round($totalDuration / $count) : 0,
        ];
    }

    public function getBlendedMargin(int $windowMinutes = 60): array
    {
        $keys = Redis::keys(self::KEY_PREFIX . 'provider:*');
        $now = now()->getTimestampMs();
        $cutoff = $now - ($windowMinutes * 60 * 1000);

        $totalCharged = 0;
        $totalCost = 0;
        $totalCount = 0;
        $perProvider = [];

        foreach ($keys as $key) {
            $entries = Redis::zrangebyscore($key, $cutoff, '+inf');
            $providerCharged = 0;
            $providerCost = 0;

            foreach ($entries as $raw) {
                $entry = json_decode($raw, true);
                $providerCharged += $entry['tokens_charged'] ?? 0;
                $providerCost += $entry['cost_tokens'] ?? 0;
            }

            $totalCharged += $providerCharged;
            $totalCost += $providerCost;
            $totalCount += count($entries);

            // Extract provider ID from key
            preg_match('/provider:(\d+)/', $key, $m);
            if (isset($m[1])) {
                $perProvider[(int) $m[1]] = [
                    'margin' => $this->calculateMargin($providerCharged, $providerCost),
                    'executions' => count($entries),
                    'tokens_charged' => $providerCharged,
                ];
            }
        }

        return [
            'blended_margin' => $this->calculateMargin($totalCharged, $totalCost),
            'total_executions' => $totalCount,
            'total_tokens_charged' => $totalCharged,
            'total_cost_tokens' => $totalCost,
            'per_provider' => $perProvider,
        ];
    }

    public function getSummary(): array
    {
        return [
            '1h' => $this->getBlendedMargin(self::WINDOWS['1h']),
            '24h' => $this->getBlendedMargin(self::WINDOWS['24h']),
            '7d' => $this->getBlendedMargin(self::WINDOWS['7d']),
        ];
    }
}
```

**Step 4: Run tests**

Run: `cd backend && php artisan test --filter=MarginalityTrackerServiceTest`
Expected: 4 tests PASS

**Step 5: Commit**

```bash
git add backend/app/Services/MarginalityTrackerService.php backend/tests/Unit/Services/MarginalityTrackerServiceTest.php
git commit -m "feat(M8): add MarginalityTrackerService with sliding window calculations"
```

---

### Task 10: Create RoutingPolicyService (reference, superseded by Phase 13 routing bindings)

> Superseded in Iteration 1 by immutable `routing_bindings` + `active_routing_binding_id` pointer controls defined in `.cursor/plans/phase13_m6_routing_apply_rollback_manifest_tdd.plan.md`. Keep this section for historical context only.

**Files:**
- Create: `backend/app/Services/RoutingPolicyService.php`
- Create: `backend/app/Models/RoutingManifestSnapshot.php`
- Create: `backend/database/migrations/2026_03_01_000007_create_routing_manifest_snapshots_table.php`
- Test: `backend/tests/Unit/Services/RoutingPolicyServiceTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Services/RoutingPolicyServiceTest.php
namespace Tests\Unit\Services;

use App\Models\Provider;
use App\Services\MarginalityTrackerService;
use App\Services\RoutingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RoutingPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_manifest_ranking_providers_by_margin(): void
    {
        // Create providers
        Provider::create(['name' => 'P1', 'slug' => 'p1', 'adapter_class' => 'A', 'is_active' => true, 'health_score' => 0.95, 'supported_effect_types' => ['style_transfer']]);
        Provider::create(['name' => 'P2', 'slug' => 'p2', 'adapter_class' => 'B', 'is_active' => true, 'health_score' => 0.90, 'supported_effect_types' => ['style_transfer']]);

        $marginalityService = $this->createMock(MarginalityTrackerService::class);
        $marginalityService->method('getMarginForWindow')
            ->willReturnOnConsecutiveCalls(
                ['margin' => 0.40, 'execution_count' => 100, 'total_tokens_charged' => 10000, 'total_cost_tokens' => 6000, 'avg_duration_ms' => 5000],
                ['margin' => 0.25, 'execution_count' => 80, 'total_tokens_charged' => 8000, 'total_cost_tokens' => 6000, 'avg_duration_ms' => 7000],
            );

        $service = new RoutingPolicyService($marginalityService);
        $manifest = $service->generateManifest();

        $this->assertArrayHasKey('style_transfer', $manifest);
        // P1 should be ranked first (higher margin)
        $this->assertEquals('p1', $manifest['style_transfer'][0]['slug']);
    }

    public function test_excludes_unhealthy_providers(): void
    {
        Provider::create(['name' => 'Healthy', 'slug' => 'h', 'adapter_class' => 'A', 'is_active' => true, 'health_score' => 0.9, 'supported_effect_types' => ['upscale']]);
        Provider::create(['name' => 'Unhealthy', 'slug' => 'u', 'adapter_class' => 'B', 'is_active' => true, 'health_score' => 0.1, 'supported_effect_types' => ['upscale']]);

        $marginalityService = $this->createMock(MarginalityTrackerService::class);
        $marginalityService->method('getMarginForWindow')
            ->willReturn(['margin' => 0.30, 'execution_count' => 50, 'total_tokens_charged' => 5000, 'total_cost_tokens' => 3500, 'avg_duration_ms' => 4000]);

        $service = new RoutingPolicyService($marginalityService);
        $manifest = $service->generateManifest();

        $this->assertCount(1, $manifest['upscale']);
        $this->assertEquals('h', $manifest['upscale'][0]['slug']);
    }

    public function test_publishes_manifest_to_redis(): void
    {
        Redis::shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $value) {
                return $key === 'es:routing_manifest'
                    && json_decode($value, true) !== null;
            });

        Provider::create(['name' => 'P', 'slug' => 'p', 'adapter_class' => 'A', 'is_active' => true, 'health_score' => 0.9, 'supported_effect_types' => ['style']]);

        $marginalityService = $this->createMock(MarginalityTrackerService::class);
        $marginalityService->method('getMarginForWindow')
            ->willReturn(['margin' => 0.30, 'execution_count' => 50, 'total_tokens_charged' => 5000, 'total_cost_tokens' => 3500, 'avg_duration_ms' => 4000]);

        $service = new RoutingPolicyService($marginalityService);
        $service->publishManifest();
    }

    public function test_read_manifest_returns_cached_data(): void
    {
        $manifest = ['style_transfer' => [['slug' => 'p1', 'margin' => 0.40]]];
        Redis::shouldReceive('get')
            ->once()
            ->with('es:routing_manifest')
            ->andReturn(json_encode($manifest));

        $service = new RoutingPolicyService($this->createMock(MarginalityTrackerService::class));
        $result = $service->readManifest();

        $this->assertEquals('p1', $result['style_transfer'][0]['slug']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=RoutingPolicyServiceTest`
Expected: FAIL

**Step 3: Write migration for snapshots**

```php
<?php
// backend/database/migrations/2026_03_01_000007_create_routing_manifest_snapshots_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('routing_manifest_snapshots', function (Blueprint $table) {
            $table->id();
            $table->json('manifest');
            $table->string('trigger', 50)->default('scheduled');
            $table->integer('provider_count')->default(0);
            $table->integer('effect_type_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_manifest_snapshots');
    }
};
```

**Step 4: Write model**

```php
<?php
// backend/app/Models/RoutingManifestSnapshot.php
namespace App\Models;

class RoutingManifestSnapshot extends CentralModel
{
    protected $fillable = ['manifest', 'trigger', 'provider_count', 'effect_type_count'];
    protected $casts = ['manifest' => 'array'];
}
```

**Step 5: Write the service**

```php
<?php
// backend/app/Services/RoutingPolicyService.php
namespace App\Services;

use App\Models\Provider;
use App\Models\RoutingManifestSnapshot;
use Illuminate\Support\Facades\Redis;

class RoutingPolicyService
{
    private const MANIFEST_KEY = 'es:routing_manifest';
    private const DEFAULT_WINDOW_MINUTES = 1440; // 24h

    public function __construct(
        private readonly MarginalityTrackerService $marginalityTracker,
    ) {}

    public function generateManifest(int $windowMinutes = self::DEFAULT_WINDOW_MINUTES): array
    {
        $providers = Provider::query()->active()->healthy()->get();
        $manifest = [];

        foreach ($providers as $provider) {
            $marginData = $this->marginalityTracker->getMarginForWindow(
                $provider->id,
                $windowMinutes
            );

            foreach ($provider->supported_effect_types ?? [] as $effectType) {
                $manifest[$effectType][] = [
                    'provider_id' => $provider->id,
                    'slug' => $provider->slug,
                    'adapter_class' => $provider->adapter_class,
                    'margin' => $marginData['margin'],
                    'avg_duration_ms' => $marginData['avg_duration_ms'],
                    'execution_count' => $marginData['execution_count'],
                    'health_score' => $provider->health_score,
                    'routing_weight' => $provider->routing_weight,
                ];
            }
        }

        // Sort each effect type by composite score (margin * health * weight)
        foreach ($manifest as $effectType => &$entries) {
            usort($entries, function ($a, $b) {
                $scoreA = max(0, $a['margin']) * $a['health_score'] * $a['routing_weight'];
                $scoreB = max(0, $b['margin']) * $b['health_score'] * $b['routing_weight'];
                return $scoreB <=> $scoreA;
            });
        }

        return $manifest;
    }

    public function publishManifest(string $trigger = 'scheduled'): void
    {
        $manifest = $this->generateManifest();

        Redis::set(self::MANIFEST_KEY, json_encode($manifest));

        // Store snapshot for audit trail
        RoutingManifestSnapshot::create([
            'manifest' => $manifest,
            'trigger' => $trigger,
            'provider_count' => Provider::active()->healthy()->count(),
            'effect_type_count' => count($manifest),
        ]);
    }

    public function readManifest(): array
    {
        $raw = Redis::get(self::MANIFEST_KEY);
        if (!$raw) {
            return [];
        }
        return json_decode($raw, true) ?: [];
    }

    public function getProvidersForEffectType(string $effectType, int $limit = 3): array
    {
        $manifest = $this->readManifest();
        $entries = $manifest[$effectType] ?? [];
        return array_slice($entries, 0, $limit);
    }
}
```

**Step 6: Run tests**

Run: `cd backend && php artisan migrate && php artisan test --filter=RoutingPolicyServiceTest`
Expected: 4 tests PASS

**Step 7: Commit**

```bash
git add backend/app/Services/RoutingPolicyService.php backend/app/Models/RoutingManifestSnapshot.php backend/database/migrations/2026_03_01_000007_create_routing_manifest_snapshots_table.php backend/tests/Unit/Services/RoutingPolicyServiceTest.php
git commit -m "feat(M8): add RoutingPolicyService with Redis manifest and provider ranking"
```

---

### Task 11: Create RoutingManifest Scheduled Command

**Files:**
- Create: `backend/app/Console/Commands/GenerateRoutingManifest.php`
- Modify: `backend/app/Console/Kernel.php` (add schedule)
- Test: `backend/tests/Feature/Commands/GenerateRoutingManifestTest.php`

**Step 1: Write the failing test**

```php
<?php
// backend/tests/Feature/Commands/GenerateRoutingManifestTest.php
namespace Tests\Feature\Commands;

use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class GenerateRoutingManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_generates_and_publishes_manifest(): void
    {
        Provider::create([
            'name' => 'TestProvider', 'slug' => 'test-p', 'adapter_class' => 'App\\Adapters\\TestAdapter',
            'is_active' => true, 'health_score' => 0.95, 'supported_effect_types' => ['style'],
        ]);

        $this->artisan('es:generate-routing-manifest')
            ->assertExitCode(0);
    }
}
```

**Step 2: Write the command**

```php
<?php
// backend/app/Console/Commands/GenerateRoutingManifest.php
namespace App\Console\Commands;

use App\Services\RoutingPolicyService;
use Illuminate\Console\Command;

class GenerateRoutingManifest extends Command
{
    protected $signature = 'es:generate-routing-manifest {--trigger=scheduled}';
    protected $description = 'Generate and publish the routing policy manifest to Redis';

    public function handle(RoutingPolicyService $service): int
    {
        $trigger = $this->option('trigger');
        $service->publishManifest($trigger);

        $manifest = $service->readManifest();
        $effectTypes = count($manifest);
        $totalProviders = array_sum(array_map('count', $manifest));

        $this->info("Routing manifest published: {$effectTypes} effect types, {$totalProviders} provider entries.");
        return self::SUCCESS;
    }
}
```

Schedule: Add `$schedule->command('es:generate-routing-manifest')->everyFifteenMinutes();` to `schedule()` in `Kernel.php` or `routes/console.php`.

Run: `cd backend && php artisan test --filter=GenerateRoutingManifestTest`

Commit:
```bash
git add backend/app/Console/Commands/GenerateRoutingManifest.php backend/tests/Feature/Commands/
git commit -m "feat(M8): add scheduled routing manifest generation command (every 15 min)"
```

---

### Task 12: Create Economic Engine Admin API Endpoints

**Files:**
- Create: `backend/app/Http/Controllers/Admin/EconomicEngineController.php`
- Modify: `backend/routes/api.php` (add routes)
- Test: `backend/tests/Feature/Admin/EconomicEngineControllerTest.php`

**Step 1: Write the controller**

```php
<?php
// backend/app/Http/Controllers/Admin/EconomicEngineController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\EconomicRecommendation;
use App\Models\RoutingManifestSnapshot;
use App\Services\MarginalityTrackerService;
use App\Services\RoutingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EconomicEngineController extends BaseController
{
    public function __construct(
        private readonly MarginalityTrackerService $marginalityTracker,
        private readonly RoutingPolicyService $routingPolicy,
    ) {}

    /**
     * GET /admin/economics/summary
     * Real-time economic summary for HUD.
     */
    public function summary(): JsonResponse
    {
        $windows = $this->marginalityTracker->getSummary();
        return $this->sendResponse($windows, 'Economic summary');
    }

    /**
     * GET /admin/economics/provider-matrix?window=24h
     * Provider comparison matrix with margin, quality, cost data.
     */
    public function providerMatrix(Request $request): JsonResponse
    {
        $window = match ($request->query('window', '24h')) {
            '1h' => 60,
            '24h' => 1440,
            '7d' => 10080,
            default => 1440,
        };

        $manifest = $this->routingPolicy->readManifest();
        $providerData = [];

        foreach ($manifest as $effectType => $providers) {
            foreach ($providers as $p) {
                $id = $p['provider_id'];
                if (!isset($providerData[$id])) {
                    $marginData = $this->marginalityTracker->getMarginForWindow($id, $window);
                    $providerData[$id] = array_merge($p, $marginData, [
                        'effect_types' => [],
                    ]);
                }
                $providerData[$id]['effect_types'][] = $effectType;
            }
        }

        return $this->sendResponse(array_values($providerData), 'Provider matrix');
    }

    /**
     * GET /admin/economics/recommendations?status=pending
     */
    public function recommendations(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $recs = EconomicRecommendation::query()
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('impact_usd_per_day')
            ->limit(50)
            ->get();

        return $this->sendResponse($recs, 'Recommendations');
    }

    /**
     * POST /admin/economics/recommendations/{id}/approve
     */
    public function approveRecommendation(int $id, Request $request): JsonResponse
    {
        $rec = EconomicRecommendation::findOrFail($id);
        $rec->approve($request->user()->id);
        return $this->sendResponse($rec->fresh(), 'Recommendation approved');
    }

    /**
     * POST /admin/economics/recommendations/{id}/reject
     */
    public function rejectRecommendation(int $id, Request $request): JsonResponse
    {
        $rec = EconomicRecommendation::findOrFail($id);
        $rec->reject($request->user()->id, $request->input('reason'));
        return $this->sendResponse($rec->fresh(), 'Recommendation rejected');
    }

    /**
     * GET /admin/economics/margin-trend?range=24h
     * Margin trend data for charts.
     */
    public function marginTrend(Request $request): JsonResponse
    {
        $snapshots = RoutingManifestSnapshot::query()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['manifest', 'created_at']);

        $trend = $snapshots->map(function ($snap) {
            $totalCharged = 0;
            $totalProviders = 0;
            foreach ($snap->manifest as $providers) {
                foreach ($providers as $p) {
                    $totalCharged += $p['execution_count'] ?? 0;
                    $totalProviders++;
                }
            }
            return [
                'timestamp' => $snap->created_at->toIso8601String(),
                'provider_count' => $totalProviders,
            ];
        });

        return $this->sendResponse($trend, 'Margin trend');
    }

    /**
     * GET /admin/economics/exploration
     * Exploration budget and recent explorations.
     */
    public function exploration(): JsonResponse
    {
        // Epsilon rate from config (default 5%)
        $epsilon = (float) config('economics.epsilon_rate', 0.05);

        return $this->sendResponse([
            'epsilon_rate' => $epsilon,
            'exploration_budget_24h' => 0, // TODO: compute from Redis
            'exploration_spend_24h' => 0,
            'recent_explorations' => [],
        ], 'Exploration data');
    }
}
```

**Step 2: Add routes in `routes/api.php`**

Add inside the admin middleware group:

```php
// Economic Engine routes
Route::get('/admin/economics/summary', [EconomicEngineController::class, 'summary']);
Route::get('/admin/economics/provider-matrix', [EconomicEngineController::class, 'providerMatrix']);
Route::get('/admin/economics/recommendations', [EconomicEngineController::class, 'recommendations']);
Route::post('/admin/economics/recommendations/{id}/approve', [EconomicEngineController::class, 'approveRecommendation']);
Route::post('/admin/economics/recommendations/{id}/reject', [EconomicEngineController::class, 'rejectRecommendation']);
Route::get('/admin/economics/margin-trend', [EconomicEngineController::class, 'marginTrend']);
Route::get('/admin/economics/exploration', [EconomicEngineController::class, 'exploration']);
```

**Step 3: Run tests**

Run: `cd backend && php artisan test --filter=EconomicEngineControllerTest`

**Step 4: Commit**

```bash
git add backend/app/Http/Controllers/Admin/EconomicEngineController.php backend/routes/api.php backend/tests/Feature/Admin/
git commit -m "feat(M8): add Economic Engine admin API endpoints"
```

---

### Task 13: Create Admin Economics Dashboard Frontend (Phase 2 - Frontend)

**Files:**
- Create: `frontend/src/components/admin/EconomicKpi.tsx`
- Create: `frontend/src/components/admin/StatusDot.tsx`
- Create: `frontend/src/components/admin/SeverityBadge.tsx`
- Create: `frontend/src/components/admin/RecommendationCard.tsx`
- Modify: `frontend/src/lib/api.ts` (add API functions)
- Modify: `frontend/src/app/admin/economics/page.tsx` (extend with HUD and new panels)
- Modify: `frontend/src/app/admin/layout.tsx` (add new nav groups)

**Step 1: Create shared admin components**

`EconomicKpi.tsx`:
```tsx
"use client";
import { cn } from "@/lib/utils";
import { TrendingUp, TrendingDown, Minus } from "lucide-react";

interface EconomicKpiProps {
  label: string;
  value: string | number;
  previousValue?: number;
  unit?: string;
  trend?: "up" | "down" | "flat";
  trendIsGood?: boolean;
  className?: string;
}

export function EconomicKpi({ label, value, previousValue, unit, trend, trendIsGood = true, className }: EconomicKpiProps) {
  const TrendIcon = trend === "up" ? TrendingUp : trend === "down" ? TrendingDown : Minus;
  const trendColor = trend === "flat" ? "text-muted-foreground" :
    (trend === "up" && trendIsGood) || (trend === "down" && !trendIsGood) ? "text-[var(--color-status-healthy)]" : "text-[var(--color-status-critical)]";

  return (
    <div className={cn("flex flex-col gap-0.5 px-4 py-2", className)}>
      <span className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">{label}</span>
      <div className="flex items-baseline gap-1.5">
        <span className="text-lg font-semibold tabular-nums">{value}</span>
        {unit && <span className="text-xs text-muted-foreground">{unit}</span>}
        {trend && <TrendIcon className={cn("h-3.5 w-3.5", trendColor)} />}
      </div>
    </div>
  );
}
```

`StatusDot.tsx`:
```tsx
import { cn } from "@/lib/utils";

interface StatusDotProps {
  status: "healthy" | "warning" | "critical" | "neutral";
  className?: string;
}

const statusColors = {
  healthy: "bg-[var(--color-status-healthy)]",
  warning: "bg-[var(--color-status-warning)]",
  critical: "bg-[var(--color-status-critical)]",
  neutral: "bg-[var(--color-status-neutral)]",
};

export function StatusDot({ status, className }: StatusDotProps) {
  return (
    <span className={cn("inline-block h-2 w-2 rounded-full", statusColors[status], className)} />
  );
}
```

`SeverityBadge.tsx`:
```tsx
import { cn } from "@/lib/utils";

interface SeverityBadgeProps {
  severity: "CRITICAL" | "HIGH" | "MEDIUM" | "LOW" | "INFO";
  className?: string;
}

const severityStyles = {
  CRITICAL: "bg-red-100 text-red-700 border-red-200",
  HIGH: "bg-orange-100 text-orange-700 border-orange-200",
  MEDIUM: "bg-amber-100 text-amber-700 border-amber-200",
  LOW: "bg-blue-100 text-blue-700 border-blue-200",
  INFO: "bg-slate-100 text-slate-600 border-slate-200",
};

export function SeverityBadge({ severity, className }: SeverityBadgeProps) {
  return (
    <span className={cn("inline-flex items-center px-2 py-0.5 text-[10px] font-semibold rounded-full border", severityStyles[severity], className)}>
      {severity}
    </span>
  );
}
```

`RecommendationCard.tsx`:
```tsx
"use client";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { ArrowRightLeft, DollarSign, Server, Wrench } from "lucide-react";

interface RecommendationCardProps {
  type: string;
  title: string;
  description?: string;
  confidence: number;
  impactPerDay: number;
  basedOnExecutions: number;
  onApprove: () => void;
  onReject: () => void;
  onDefer?: () => void;
}

const typeIcons: Record<string, typeof ArrowRightLeft> = {
  PROVIDER_SWITCH: ArrowRightLeft,
  PRICE_ADJUSTMENT: DollarSign,
  FLEET_OPTIMIZATION: Server,
  WORKFLOW_TUNING: Wrench,
};

export function RecommendationCard({ type, title, description, confidence, impactPerDay, basedOnExecutions, onApprove, onReject, onDefer }: RecommendationCardProps) {
  const Icon = typeIcons[type] ?? Wrench;
  return (
    <Card className="border-l-4 border-l-primary/60">
      <CardContent className="p-4 space-y-3">
        <div className="flex items-start gap-3">
          <div className="p-2 rounded-md bg-primary/10"><Icon className="h-4 w-4 text-primary" /></div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium leading-tight">{title}</p>
            {description && <p className="text-xs text-muted-foreground mt-1">{description}</p>}
          </div>
        </div>
        <div className="flex items-center gap-4 text-xs text-muted-foreground">
          <span>{Math.round(confidence * 100)}% confidence</span>
          <span className={cn("font-semibold", impactPerDay > 0 ? "text-[var(--color-margin-positive)]" : "text-[var(--color-margin-negative)]")}>
            {impactPerDay > 0 ? "+" : ""}${impactPerDay.toFixed(2)}/day
          </span>
          <span>{basedOnExecutions} executions</span>
        </div>
        <div className="flex gap-2">
          <Button size="sm" onClick={onApprove}>Approve</Button>
          <Button size="sm" variant="destructive" onClick={onReject}>Reject</Button>
          {onDefer && <Button size="sm" variant="outline" onClick={onDefer}>Defer</Button>}
        </div>
      </CardContent>
    </Card>
  );
}
```

**Step 2: Add API functions to `frontend/src/lib/api.ts`**

Add the following exports:

```typescript
// Economic Engine API
export interface EconomicSummaryWindow {
  blended_margin: number;
  total_executions: number;
  total_tokens_charged: number;
  total_cost_tokens: number;
  per_provider: Record<number, { margin: number; executions: number; tokens_charged: number }>;
}

export interface EconomicSummary {
  "1h": EconomicSummaryWindow;
  "24h": EconomicSummaryWindow;
  "7d": EconomicSummaryWindow;
}

export interface ProviderMatrixEntry {
  provider_id: number;
  slug: string;
  margin: number;
  avg_duration_ms: number;
  execution_count: number;
  health_score: number;
  effect_types: string[];
}

export interface EconomicRecommendation {
  id: number;
  type: string;
  title: string;
  description: string | null;
  confidence: number;
  impact_usd_per_day: number;
  based_on_executions: number;
  status: string;
  details: Record<string, unknown> | null;
}

export function getEconomicSummary(): Promise<EconomicSummary> {
  return apiGet<EconomicSummary>("/admin/economics/summary");
}

export function getProviderMatrix(window?: string): Promise<ProviderMatrixEntry[]> {
  return apiGet<ProviderMatrixEntry[]>("/admin/economics/provider-matrix", window ? { window } : undefined);
}

export function getEconomicRecommendations(status?: string): Promise<EconomicRecommendation[]> {
  return apiGet<EconomicRecommendation[]>("/admin/economics/recommendations", status ? { status } : undefined);
}

export function approveRecommendation(id: number): Promise<EconomicRecommendation> {
  return apiPost<EconomicRecommendation>(`/admin/economics/recommendations/${id}/approve`);
}

export function rejectRecommendation(id: number, reason?: string): Promise<EconomicRecommendation> {
  return apiPost<EconomicRecommendation>(`/admin/economics/recommendations/${id}/reject`, reason ? { reason } : undefined);
}
```

**Step 3: Update admin sidebar in `frontend/src/app/admin/layout.tsx`**

Add the "Intelligence" nav group between "Application" and "ComfyUI Ops":

```typescript
{
  label: "Intelligence",
  items: [
    { label: "Economics", href: "/admin/economics", icon: TrendingUp },
    { label: "Bottlenecks", href: "/admin/bottlenecks", icon: AlertTriangle },
    { label: "Providers", href: "/admin/providers", icon: Server },
    { label: "Approvals", href: "/admin/approvals", icon: CheckSquare },
  ],
},
```

Import the new icons: `import { TrendingUp, AlertTriangle, Server, CheckSquare } from "lucide-react";`

Remove "Economics" from the "Platform Ops" group since it moves to "Intelligence".

**Step 4: Commit**

```bash
git add frontend/src/components/admin/ frontend/src/lib/api.ts frontend/src/app/admin/layout.tsx
git commit -m "feat(M8): add Economic Engine shared components, API functions, and nav update"
```

---

### Task 14: Build Economic Engine Dashboard Page

This is the #1 admin page. See `docs/plans/2026-02-28-effect-studio-ui-ux-design.md` Section 3.1 for the complete wireframe and component hierarchy.

**Files:**
- Modify: `frontend/src/app/admin/economics/page.tsx` (major extension)

The existing page has settings, partner pricing, and unit economics tabs. The new page wraps these in a tabbed view with the Margin HUD as a sticky bar above all content, and adds new tabs for the economic engine dashboard.

**Implementation approach:**
1. Keep existing tabs (Settings, Partner Pricing, Unit Economics) as-is
2. Add the Margin HUD bar above the tabs (always visible)
3. Add new tabs: Dashboard (default), Recommendations, Exploration
4. Dashboard tab contains: Provider Matrix table, Margin Trend chart (Recharts LineChart), Cost Drilldown
5. Recommendations tab: list of `RecommendationCard` components
6. Exploration tab: epsilon rate display, recent explorations

Since this is a 50KB+ file, the implementation should be done incrementally — add the HUD first, then add one new tab at a time. Each sub-step is its own commit.

**This task is too large for a single step. Break into sub-tasks:**
- 14a: Add MarginHud sticky bar above existing tabs
- 14b: Add Dashboard tab with Provider Matrix table
- 14c: Add Margin Trend chart using Recharts
- 14d: Add Recommendations tab with RecommendationCard list

Each sub-task follows the same pattern: read existing file, add the new section, test in browser, commit.

---

## Phase 3: Analysis & Generation Pipeline (Tasks 15-22)

### Task 15: Create ContentAnalysisService (M2)

**Files:**
- Create: `backend/app/Services/ContentAnalysisService.php`
- Test: `backend/tests/Unit/Services/ContentAnalysisServiceTest.php`

**Purpose:** Accepts a `VideoIngested` event payload, extracts frames from the video, sends them to Gemini Pro Vision for multimodal analysis, returns structured `ContentAnalyzed` event payload.

**Key patterns to follow:**
- `WorkflowAnalyzerService.php` — schema-first AI analysis with versioned prompts
- Use `PROMPT_VERSION` and `SCHEMA_VERSION` constants
- Return normalized structured output matching the contract:
  ```php
  ['scenes' => [], 'dominant_style' => '', 'motion_pattern' => '', 'detected_effects' => [], 'eligible_effect_types' => [], 'user_description' => '']
  ```

**Dependencies:** Gemini Pro Vision API client (new — use HTTP client with `google-ai-studio` API key)

---

### Task 16: Create PromptGenerationService (M4)

**Files:**
- Create: `backend/app/Services/PromptGenerationService.php`
- Test: `backend/tests/Unit/Services/PromptGenerationServiceTest.php`

**Purpose:** Translates `ContentAnalyzed` event + user preferences into provider-agnostic prompt variants.

---

### Task 17: Create QualityEvaluationService (M7)

**Files:**
- Create: `backend/app/Services/QualityEvaluationService.php`
- Test: `backend/tests/Unit/Services/QualityEvaluationServiceTest.php`

**Purpose:** Accepts output video URI + input reference URI, sends to Gemini Pro Vision for comparative quality scoring across 4 dimensions (fidelity, artifacts, style_adherence, temporal_consistency).

---

### Task 18: Create ProviderRegistryService (M3 extension)

**Files:**
- Create: `backend/app/Services/ProviderRegistryService.php`
- Create: `backend/app/Http/Controllers/Admin/ProviderController.php`
- Test: `backend/tests/Feature/Admin/ProviderControllerTest.php`

**Purpose:** CRUD for providers + capability querying + health tracking.

---

## Phase 4: Orchestrator & E2E Flow (Tasks 19-26)

### Task 19: Create WorkflowOrchestratorService (M5)

**Files:**
- Create: `backend/app/Services/WorkflowOrchestratorService.php`
- Create: `backend/app/Jobs/ProcessOrchestrationStage.php`
- Test: `backend/tests/Unit/Services/WorkflowOrchestratorServiceTest.php`

**Purpose:** Saga state machine. Each stage transition dispatches a job that:
1. Reads current `OrchestrationJob` state
2. Reads routing manifest from Redis
3. Executes the stage logic (call M2, M4, M6, M7 as needed)
4. Updates state + transitions to next stage
5. On failure: compensating actions (token refund, notification)

---

### Task 20: Create Video Submission API Endpoint

**Files:**
- Create: `backend/app/Http/Controllers/VideoSubmissionController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/VideoSubmissionControllerTest.php`

**Purpose:** User-facing endpoint to create orchestration jobs from video uploads.

---

### Task 21: Create Notification Service

**Files:**
- Create: `backend/app/Services/NotificationService.php`
- Test: `backend/tests/Unit/Services/NotificationServiceTest.php`

**Purpose:** Create user notifications, mark as read, notify on pipeline events.

---

### Task 22: Create Approval Queue Controller

**Files:**
- Create: `backend/app/Http/Controllers/Admin/ApprovalQueueController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/ApprovalQueueControllerTest.php`

---

### Task 23: Wire Gallery Auto-Publish from Approval (M10)

**Files:**
- Modify: `backend/app/Services/EffectPublicationService.php`

**Purpose:** On `AdminApproved` event → auto-create EffectRevision → publish → emit `EffectPublished` → notify user.

---

### Task 24-26: Frontend — User Submission, My Creations, Notifications Pages

Follow wireframes in `docs/plans/2026-02-28-effect-studio-ui-ux-design.md` Sections 4.1-4.3.

- **Task 24:** `/create` page — upload zone, description, style prefs, cost estimate, submit
- **Task 25:** `/my-creations` page — list view + detail view with pipeline timeline
- **Task 26:** `/notifications` page — inbox with typed notification styling

---

## Phase 5: Intelligence & Monitoring (Tasks 27-32)

### Task 27: Create BottleneckClassifierService

**Files:**
- Create: `backend/app/Services/BottleneckClassifierService.php`
- Test: `backend/tests/Unit/Services/BottleneckClassifierServiceTest.php`

**Purpose:** Two-stage classification — signal detection (CUSUM, Z-score, threshold) → decision tree (6 categories).

---

### Task 28: Create EconomicRecommendationService

**Files:**
- Create: `backend/app/Services/EconomicRecommendationService.php`
- Test: `backend/tests/Unit/Services/EconomicRecommendationServiceTest.php`

**Purpose:** Analyze execution data → generate typed recommendations (PROVIDER_SWITCH, PRICE_ADJUSTMENT, FLEET_OPTIMIZATION, WORKFLOW_TUNING).

---

### Task 29: Create Action-Oriented Logging Infrastructure

**Files:**
- Create: `backend/app/Services/ActionOrientedLogger.php`
- Test: `backend/tests/Unit/Services/ActionOrientedLoggerTest.php`

**Purpose:** Structured logging with `economic_impact` and `operator_action` on every WARN+ log.

---

### Task 30-32: Frontend — Bottleneck Monitor, Provider Management, Logs Pages

Follow wireframes in `docs/plans/2026-02-28-effect-studio-ui-ux-design.md` Sections 3.2, 3.3, 3.7.

- **Task 30:** `/admin/bottlenecks` page — status cards, signal timeline, quick actions
- **Task 31:** `/admin/providers` page — provider cards, CRUD, detail sheet, add wizard
- **Task 32:** `/admin/logs` page — action-oriented log viewer with live/paused toggle

---

## Phase 6: Economic Benchmarking & Hardening (Tasks 33-36)

### Task 33: Create Benchmark Suite Service

**Files:**
- Create: `backend/app/Services/BenchmarkSuiteService.php`
- Test: `backend/tests/Unit/Services/BenchmarkSuiteServiceTest.php`

**Purpose:** Multi-provider comparison on real GPU executions. Extends `StudioBlackboxRunnerService` pattern.

---

### Task 34: Frontend — Enhanced Studio Tabs

Add Economic Test, Benchmarks, and A/B Tests tabs to `/admin/studio`. See UI/UX design Section 3.5.

---

### Task 35: Frontend — Wallet & Enhanced Gallery

- **Task 35a:** `/wallet` page — balance, packages, transaction history
- **Task 35b:** Enhanced `/effects` gallery — AI badge, attribution, quality, sort, "Submit your own" CTA

---

### Task 36: Frontend — Admin Approval Queue Page

Full master-detail split with radar chart quality scores and provider comparison. See UI/UX design Section 3.4.

---

## Task Dependency Graph

```
Phase 1 (Foundation):
  Task 1 (Provider) → Task 2 (Adapter) → Task 18 (Registry)
  Task 3 (Redis Streams) → Task 9 (Marginality)
  Task 4 (OrchestrationJob) → Task 19 (Orchestrator)
  Task 5 (QualityScore) → Task 17 (Quality Service)
  Task 6 (EconomicRec) → Task 28 (Rec Service)
  Task 7 (Bottleneck) → Task 27 (Classifier Service)
  Task 8 (Notification + Approval) → Task 21 (Notification Service)

Phase 2 (Economic Engine):
  Task 9 (Marginality) → Task 10 (Routing Policy) → Task 11 (Scheduled Command)
  Task 12 (Admin API) → Task 13 (Frontend Components) → Task 14 (Dashboard Page)

Phase 3 (Analysis Pipeline):
  Task 15 (Content Analysis) ← Task 3 (Redis)
  Task 16 (Prompt Gen) ← Task 15
  Task 17 (Quality Eval) ← Task 5

Phase 4 (Orchestrator & E2E):
  Task 19 (Orchestrator) ← Tasks 10, 15, 16, 17, 18
  Task 20 (Submission API) ← Task 19
  Task 21 (Notification) ← Task 8
  Task 22 (Approval Queue) ← Tasks 8, 19
  Task 23 (Gallery Wire) ← Task 22
  Tasks 24-26 (Frontend) ← Tasks 20, 21

Phase 5 (Intelligence):
  Task 27 (Bottleneck) ← Tasks 7, 9
  Task 28 (Recommendations) ← Tasks 6, 9
  Task 29 (Logging) ← all services
  Tasks 30-32 (Frontend) ← Tasks 27, 28, 29

Phase 6 (Benchmarking):
  Task 33 (Benchmark) ← Tasks 17, 19
  Tasks 34-36 (Frontend) ← all backend tasks
```

---

## Verification Checklist

After each phase:

- [ ] All new tests pass: `cd backend && php artisan test`
- [ ] No existing tests broken
- [ ] Migrations run cleanly: `php artisan migrate:fresh --seed`
- [ ] Frontend builds: `cd frontend && npm run build`
- [ ] New admin pages load without errors in browser
- [ ] Redis Streams events emit correctly (check with `redis-cli XLEN studio:business`)
- [ ] Routing controls validate against active binding behavior (`active_routing_binding_id` drives deterministic public dispatch routing)
