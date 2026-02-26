<?php

namespace Tests\Feature;

use App\Models\AiJobDispatch;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorker;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\WorkerAuditLog;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetRegistrationTest extends TestCase
{
    protected static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$prepared) {
            try {
                DB::connection('central')->statement(
                    'CREATE DATABASE IF NOT EXISTS tenant_pool_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
                DB::connection('central')->statement(
                    'CREATE DATABASE IF NOT EXISTS tenant_pool_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
            } catch (\Throwable $e) {
                // ignore
            }

            Artisan::call('migrate');
            Artisan::call('tenancy:pools-migrate');
            static::$prepared = true;
        }

        config(['services.comfyui.fleet_secret_staging' => 'test-fleet-secret']);
        config(['services.comfyui.fleet_secret_production' => 'prod-secret']);
        config(['services.comfyui.registration_stage' => 'staging']);
        config(['services.comfyui.max_fleet_workers' => 5]);
        config(['app.env' => 'staging']);

        $this->resetState();
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('comfy_ui_workers')->truncate();
        DB::connection('central')->table('worker_audit_logs')->truncate();
        DB::connection('central')->table('worker_workflows')->truncate();
        DB::connection('central')->table('comfyui_workflow_fleets')->truncate();
        DB::connection('central')->table('comfyui_gpu_fleets')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('ai_job_dispatches')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ========================================================================
    // Fleet Registration
    // ========================================================================

    public function test_fleet_register_creates_worker_and_returns_token(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-abc123',
            'display_name' => 'GPU Worker 1',
            'max_concurrency' => 2,
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.worker_id', 'i-abc123');

        $this->assertNotEmpty($response->json('data.token'));

        $worker = ComfyUiWorker::query()->where('worker_id', 'i-abc123')->first();
        $this->assertNotNull($worker);
        $this->assertSame('fleet', $worker->registration_source);
        $this->assertTrue($worker->is_approved);
        $this->assertSame(2, $worker->max_concurrency);
        $this->assertSame('staging', $worker->stage);
    }

    public function test_fleet_register_assigns_workflows_by_slug(): void
    {
        $face = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $upscale = Workflow::query()->create(['name' => 'Upscale', 'slug' => 'upscale', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$face, $upscale]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-wf123',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $response->assertStatus(200);
        $assigned = $response->json('data.workflows_assigned');
        $this->assertCount(2, $assigned);
        $this->assertContains('face-swap', $assigned);
        $this->assertContains('upscale', $assigned);
    }

    public function test_fleet_register_rejects_without_workflow_slugs(): void
    {
        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-noslugs',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $response->assertStatus(422);
    }

    public function test_fleet_register_rejects_invalid_workflow_slugs(): void
    {
        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-badslugs',
            'fleet_slug' => 'missing-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $response->assertStatus(404);
    }

    public function test_fleet_register_rejects_when_fleet_has_no_workflows(): void
    {
        ComfyUiGpuFleet::query()->create([
            'stage' => 'staging',
            'slug' => 'empty-fleet',
            'name' => 'Empty Fleet',
            'instance_types' => ['g4dn.xlarge'],
            'max_size' => 1,
        ]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-empty',
            'fleet_slug' => 'empty-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $response->assertStatus(422);
    }

    public function test_fleet_register_rejects_without_fleet_secret(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-nosecret',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ]);

        $response->assertStatus(401);
    }

    public function test_fleet_register_rejects_wrong_fleet_secret(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-wrongsecret',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
    }

    public function test_fleet_register_requires_stage_specific_secret_for_staging(): void
    {
        config([
            'services.comfyui.fleet_secret_staging' => 'staging-secret',
            'services.comfyui.fleet_secret_production' => 'production-secret',
        ]);

        $workflow = Workflow::query()->create(['name' => 'Stage Test', 'slug' => 'stage-test', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'staging-fleet', [$workflow]);

        $bad = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-stage-wrong',
            'fleet_slug' => 'staging-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'production-secret',
        ]);
        $bad->assertStatus(401);

        $good = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-stage-right',
            'fleet_slug' => 'staging-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'staging-secret',
        ]);
        $good->assertStatus(200);
    }

    public function test_fleet_register_requires_stage_specific_secret_for_production(): void
    {
        config([
            'services.comfyui.fleet_secret_staging' => 'staging-secret',
            'services.comfyui.fleet_secret_production' => 'production-secret',
            'services.comfyui.registration_stage' => 'production',
        ]);

        $workflow = Workflow::query()->create(['name' => 'Prod Test', 'slug' => 'prod-test', 'is_active' => true]);
        $this->createFleetWithWorkflows('production', 'prod-fleet', [$workflow]);

        $bad = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-prod-wrong',
            'fleet_slug' => 'prod-fleet',
            'stage' => 'production',
        ], [
            'X-Fleet-Secret' => 'staging-secret',
        ]);
        $bad->assertStatus(401);

        $good = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-prod-right',
            'fleet_slug' => 'prod-fleet',
            'stage' => 'production',
        ], [
            'X-Fleet-Secret' => 'production-secret',
        ]);
        $good->assertStatus(200);
    }

    public function test_fleet_register_rejects_stage_mismatch_with_backend_registration_stage(): void
    {
        config([
            'services.comfyui.fleet_secret_staging' => 'staging-secret',
            'services.comfyui.fleet_secret_production' => 'production-secret',
            'services.comfyui.registration_stage' => 'production',
        ]);

        $workflow = Workflow::query()->create(['name' => 'Mismatch Test', 'slug' => 'mismatch-test', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'staging-fleet', [$workflow]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-stage-mismatch',
            'fleet_slug' => 'staging-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'staging-secret',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Stage mismatch. This backend accepts only production workers.');
    }

    public function test_fleet_register_rejects_duplicate_worker_id(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $this->postJson('/api/worker/register', [
            'worker_id' => 'i-dup',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ])->assertStatus(200);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-dup',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $response->assertStatus(409);
    }

    public function test_fleet_register_rejects_when_max_workers_reached(): void
    {
        config(['services.comfyui.max_fleet_workers' => 2]);
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $this->postJson('/api/worker/register', [
            'worker_id' => 'i-max1',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ])->assertStatus(200);

        $this->postJson('/api/worker/register', [
            'worker_id' => 'i-max2',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ])->assertStatus(200);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-max3',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $response->assertStatus(403);
    }

    public function test_fleet_register_logs_audit_event(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $this->postJson('/api/worker/register', [
            'worker_id' => 'i-audit',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ])->assertStatus(200);

        $log = WorkerAuditLog::query()
            ->where('event', 'registered')
            ->where('worker_identifier', 'i-audit')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('fleet', $log->metadata['registration_source']);
    }

    public function test_fleet_register_returns_503_when_not_configured(): void
    {
        config([
            'services.comfyui.fleet_secret_staging' => null,
            'services.comfyui.fleet_secret_production' => null,
        ]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-noconfig',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'anything',
        ]);

        $response->assertStatus(503);
    }

    // ========================================================================
    // Fleet Deregistration
    // ========================================================================

    public function test_fleet_deregister_removes_worker(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $reg = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-dereg',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $token = $reg->json('data.token');

        $this->postJson('/api/worker/deregister', [], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $this->assertNull(ComfyUiWorker::query()->where('worker_id', 'i-dereg')->first());
    }

    public function test_fleet_deregister_logs_audit_event(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Face Swap', 'slug' => 'face-swap', 'is_active' => true]);
        $this->createFleetWithWorkflows('staging', 'test-fleet', [$workflow]);

        $reg = $this->postJson('/api/worker/register', [
            'worker_id' => 'i-dereg-audit',
            'fleet_slug' => 'test-fleet',
            'stage' => 'staging',
        ], [
            'X-Fleet-Secret' => 'test-fleet-secret',
        ]);

        $token = $reg->json('data.token');

        $this->postJson('/api/worker/deregister', [], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $log = WorkerAuditLog::query()
            ->where('event', 'deregistered')
            ->where('worker_identifier', 'i-dereg-audit')
            ->first();

        $this->assertNotNull($log);
    }

    // ========================================================================
    // Requeue (Spot Interruption)
    // ========================================================================

    public function test_requeue_resets_dispatch_to_queued(): void
    {
        $token = $this->createApprovedWorkerWithToken('requeue-worker');
        $workflow = Workflow::query()->create(['name' => 'Requeue WF', 'slug' => 'requeue-wf-' . uniqid(), 'is_active' => true]);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => 'tenant-1',
            'tenant_job_id' => 1,
            'provider' => 'self_hosted',
            'status' => 'leased',
            'priority' => 0,
            'attempts' => 1,
            'worker_id' => 'requeue-worker',
            'lease_token' => 'lease-abc',
            'lease_expires_at' => now()->addMinutes(15),
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->postJson('/api/worker/requeue', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'lease-abc',
            'reason' => 'spot_interruption',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame('queued', $dispatch->status);
        $this->assertNull($dispatch->worker_id);
        $this->assertNull($dispatch->lease_token);
        $this->assertNull($dispatch->lease_expires_at);
        $this->assertSame(0, $dispatch->attempts);
        $this->assertStringContains('Requeued: spot_interruption', $dispatch->last_error);
    }

    public function test_requeue_rejects_invalid_lease_token(): void
    {
        $token = $this->createApprovedWorkerWithToken('requeue-invalid');
        $workflow = Workflow::query()->create(['name' => 'Requeue WF', 'slug' => 'requeue-wf-' . uniqid(), 'is_active' => true]);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => 'tenant-1',
            'tenant_job_id' => 1,
            'provider' => 'self_hosted',
            'status' => 'leased',
            'priority' => 0,
            'attempts' => 1,
            'worker_id' => 'requeue-invalid',
            'lease_token' => 'correct-token',
            'lease_expires_at' => now()->addMinutes(15),
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->postJson('/api/worker/requeue', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'wrong-token',
            'reason' => 'spot_interruption',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(404);
    }

    public function test_requeue_logs_audit_event(): void
    {
        $token = $this->createApprovedWorkerWithToken('requeue-audit-worker');
        $workflow = Workflow::query()->create(['name' => 'Requeue WF', 'slug' => 'requeue-wf-' . uniqid(), 'is_active' => true]);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => 'tenant-1',
            'tenant_job_id' => 1,
            'provider' => 'self_hosted',
            'status' => 'leased',
            'priority' => 0,
            'attempts' => 1,
            'worker_id' => 'requeue-audit-worker',
            'lease_token' => 'lease-xyz',
            'lease_expires_at' => now()->addMinutes(15),
            'workflow_id' => $workflow->id,
        ]);

        $this->postJson('/api/worker/requeue', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'lease-xyz',
            'reason' => 'capacity_rebalance',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $log = WorkerAuditLog::query()
            ->where('event', 'requeued')
            ->where('dispatch_id', $dispatch->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('capacity_rebalance', $log->metadata['reason']);
    }

    public function test_requeue_does_not_decrement_below_zero(): void
    {
        $token = $this->createApprovedWorkerWithToken('requeue-zero');
        $workflow = Workflow::query()->create(['name' => 'Requeue WF', 'slug' => 'requeue-wf-' . uniqid(), 'is_active' => true]);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => 'tenant-1',
            'tenant_job_id' => 1,
            'provider' => 'self_hosted',
            'status' => 'leased',
            'priority' => 0,
            'attempts' => 0,
            'worker_id' => 'requeue-zero',
            'lease_token' => 'lease-zero',
            'lease_expires_at' => now()->addMinutes(15),
            'workflow_id' => $workflow->id,
        ]);

        $this->postJson('/api/worker/requeue', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'lease-zero',
            'reason' => 'spot_interruption',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $dispatch->refresh();
        $this->assertSame(0, $dispatch->attempts);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createApprovedWorkerWithToken(string $workerId): string
    {
        $token = 'test-token-' . $workerId . '-' . uniqid();
        ComfyUiWorker::query()->create([
            'worker_id' => $workerId,
            'token_hash' => hash('sha256', $token),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 5,
        ]);
        return $token;
    }

    private static function assertStringContains(string $needle, ?string $haystack): void
    {
        self::assertNotNull($haystack);
        self::assertStringContainsString($needle, $haystack);
    }

    private function createFleetWithWorkflows(string $stage, string $fleetSlug, array $workflows): ComfyUiGpuFleet
    {
        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => $stage,
            'slug' => $fleetSlug,
            'name' => 'Fleet ' . uniqid(),
            'instance_types' => ['g4dn.xlarge'],
            'max_size' => 1,
        ]);

        foreach ($workflows as $workflow) {
            ComfyUiWorkflowFleet::query()->create([
                'workflow_id' => $workflow->id,
                'fleet_id' => $fleet->id,
                'stage' => $stage,
                'assigned_at' => now(),
                'assigned_by_email' => 'tests@example.com',
            ]);
        }

        return $fleet;
    }
}
