<?php

namespace Tests\Feature;

use App\Models\ComfyUiWorker;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkerAuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminWorkersTest extends TestCase
{
    protected static bool $prepared = false;

    private User $adminUser;
    private Tenant $tenant;

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

        config(['app.url' => 'http://test.example.com']);
        url()->forceRootUrl('http://test.example.com');

        $this->resetState();

        [$this->adminUser, $this->tenant] = $this->createAdminUserTenant();
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->table('comfy_ui_workers')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('worker_workflows')->truncate();
        DB::connection('central')->table('worker_audit_logs')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createAdminUserTenant(): array
    {
        $user = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'db_pool' => 'tenant_pool_1',
        ]);

        return [$user, $tenant];
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser);
    }

    private function adminGet(string $uri, array $query = [])
    {
        $this->actAsAdmin();
        $url = $uri . ($query ? '?' . http_build_query($query) : '');
        return $this->getJson($url);
    }

    private function adminPost(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->postJson($uri, $data);
    }

    private function adminPatch(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->patchJson($uri, $data);
    }

    private function adminPut(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->putJson($uri, $data);
    }

    private function createWorker(array $overrides = []): ComfyUiWorker
    {
        $uid = uniqid();
        $defaults = [
            'worker_id' => 'worker-' . $uid,
            'display_name' => 'Worker ' . $uid,
            'token_hash' => hash('sha256', 'token-' . $uid),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 2,
            'last_seen_at' => now(),
        ];

        return ComfyUiWorker::query()->create(array_merge($defaults, $overrides));
    }

    private function createWorkflow(array $overrides = []): Workflow
    {
        $uid = uniqid();
        $defaults = [
            'name' => 'Workflow ' . $uid,
            'slug' => 'workflow-' . $uid,
            'is_active' => true,
        ];

        return Workflow::query()->create(array_merge($defaults, $overrides));
    }

    private function createAuditLog(array $overrides = []): WorkerAuditLog
    {
        $defaults = [
            'worker_id' => null,
            'worker_identifier' => 'test-worker',
            'event' => 'poll',
            'dispatch_id' => null,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ];

        return WorkerAuditLog::query()->create(array_merge($defaults, $overrides));
    }

    // ========================================================================
    // Tests
    // ========================================================================

    public function test_workers_index_requires_admin(): void
    {
        $this->getJson('/api/admin/workers')->assertStatus(401);

        $nonAdmin = User::factory()->create(['is_admin' => false]);
        Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $nonAdmin->id,
            'db_pool' => 'tenant_pool_1',
        ]);
        Sanctum::actingAs($nonAdmin);
        $this->getJson('/api/admin/workers')->assertStatus(403);
    }

    public function test_workers_index_returns_paginated_list_with_workflow_count(): void
    {
        $worker = $this->createWorker();
        $wf1 = $this->createWorkflow();
        $wf2 = $this->createWorkflow();
        $worker->workflows()->sync([$wf1->id, $wf2->id]);

        $response = $this->adminGet('/api/admin/workers');
        $response->assertStatus(200);

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['workflows_count']);
    }

    public function test_workers_index_search_by_worker_id_and_display_name(): void
    {
        $this->createWorker(['worker_id' => 'gpu-node-1', 'display_name' => 'GPU Node Alpha']);
        $this->createWorker(['worker_id' => 'cpu-node-2', 'display_name' => 'CPU Node Beta']);

        $response = $this->adminGet('/api/admin/workers', ['search' => 'gpu-node']);
        $this->assertSame(1, $response->json('data.totalItems'));

        $response2 = $this->adminGet('/api/admin/workers', ['search' => 'Beta']);
        $this->assertSame(1, $response2->json('data.totalItems'));
    }

    public function test_workers_show_includes_workflows_and_audit_logs(): void
    {
        $worker = $this->createWorker();
        $wf = $this->createWorkflow();
        $worker->workflows()->sync([$wf->id]);

        // Create 25 audit logs so we can verify max 20 returned
        for ($i = 0; $i < 25; $i++) {
            $this->createAuditLog([
                'worker_id' => $worker->id,
                'event' => 'poll',
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->adminGet("/api/admin/workers/{$worker->id}");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('workflows', $data);
        $this->assertCount(1, $data['workflows']);
        $this->assertArrayHasKey('recent_audit_logs', $data);
        $this->assertCount(20, $data['recent_audit_logs']);
    }

    public function test_workers_show_returns_404_for_missing(): void
    {
        $this->adminGet('/api/admin/workers/99999')->assertStatus(404);
    }

    public function test_workers_update_display_name_and_is_draining(): void
    {
        $worker = $this->createWorker(['display_name' => 'Old Name', 'is_draining' => false]);

        $response = $this->adminPatch("/api/admin/workers/{$worker->id}", [
            'display_name' => 'New Name',
            'is_draining' => true,
        ]);

        $response->assertStatus(200);

        $worker->refresh();
        $this->assertSame('New Name', $worker->display_name);
        $this->assertTrue($worker->is_draining);
    }

    public function test_workers_update_returns_404_for_missing(): void
    {
        $this->adminPatch('/api/admin/workers/99999', ['display_name' => 'x'])->assertStatus(404);
    }

    public function test_workers_approve_sets_is_approved_and_logs(): void
    {
        $worker = $this->createWorker(['is_approved' => false]);

        $response = $this->adminPost("/api/admin/workers/{$worker->id}/approve");
        $response->assertStatus(200);

        $worker->refresh();
        $this->assertTrue($worker->is_approved);

        $log = WorkerAuditLog::query()
            ->where('worker_id', $worker->id)
            ->where('event', 'approved')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_workers_approve_is_idempotent(): void
    {
        $worker = $this->createWorker(['is_approved' => true]);

        $response = $this->adminPost("/api/admin/workers/{$worker->id}/approve");
        $response->assertStatus(200);

        $worker->refresh();
        $this->assertTrue($worker->is_approved);
    }

    public function test_workers_revoke_clears_approval_and_logs(): void
    {
        $worker = $this->createWorker(['is_approved' => true]);

        $response = $this->adminPost("/api/admin/workers/{$worker->id}/revoke");
        $response->assertStatus(200);

        $worker->refresh();
        $this->assertFalse($worker->is_approved);

        $log = WorkerAuditLog::query()
            ->where('worker_id', $worker->id)
            ->where('event', 'revoked')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_workers_rotate_token_returns_plaintext_once(): void
    {
        $worker = $this->createWorker();

        $response = $this->adminPost("/api/admin/workers/{$worker->id}/rotate-token");
        $response->assertStatus(200);

        $token = $response->json('data.token');
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token));

        $dbHash = DB::connection('central')->table('comfy_ui_workers')
            ->where('id', $worker->id)
            ->value('token_hash');
        $this->assertSame(hash('sha256', $token), $dbHash);
    }

    public function test_workers_rotate_token_invalidates_previous_token(): void
    {
        $oldToken = 'old-known-token-for-rotation-test';
        $worker = $this->createWorker(['token_hash' => hash('sha256', $oldToken)]);

        $response = $this->adminPost("/api/admin/workers/{$worker->id}/rotate-token");
        $newToken = $response->json('data.token');

        $dbHash = DB::connection('central')->table('comfy_ui_workers')
            ->where('id', $worker->id)
            ->value('token_hash');

        $this->assertNotSame(hash('sha256', $oldToken), $dbHash);
        $this->assertSame(hash('sha256', $newToken), $dbHash);
    }

    public function test_workers_assign_workflows_syncs_pivot(): void
    {
        $worker = $this->createWorker();
        $wf1 = $this->createWorkflow();
        $wf2 = $this->createWorkflow();
        $wf3 = $this->createWorkflow();

        // Initially assign wf3
        $worker->workflows()->sync([$wf3->id]);

        // Sync to wf1 and wf2 (should remove wf3)
        $response = $this->adminPut("/api/admin/workers/{$worker->id}/workflows", [
            'workflow_ids' => [$wf1->id, $wf2->id],
        ]);
        $response->assertStatus(200);

        $assignedIds = $worker->workflows()->pluck('workflows.id')->sort()->values()->toArray();
        $this->assertSame([$wf1->id, $wf2->id], $assignedIds);
    }

    public function test_workers_assign_workflows_with_empty_array_clears_all(): void
    {
        $worker = $this->createWorker();
        $wf = $this->createWorkflow();
        $worker->workflows()->sync([$wf->id]);

        $response = $this->adminPut("/api/admin/workers/{$worker->id}/workflows", [
            'workflow_ids' => [],
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, $worker->workflows()->count());
    }

    public function test_workers_assign_workflows_rejects_nonexistent_workflow(): void
    {
        $worker = $this->createWorker();

        $response = $this->adminPut("/api/admin/workers/{$worker->id}/workflows", [
            'workflow_ids' => [99999],
        ]);

        $response->assertStatus(422);
    }

    public function test_workers_assign_workflows_returns_404_for_missing_worker(): void
    {
        $this->adminPut('/api/admin/workers/99999/workflows', [
            'workflow_ids' => [],
        ])->assertStatus(404);
    }

    public function test_workers_audit_logs_returns_paginated_logs_for_worker(): void
    {
        $workerA = $this->createWorker();
        $workerB = $this->createWorker();

        $this->createAuditLog(['worker_id' => $workerA->id, 'event' => 'poll']);
        $this->createAuditLog(['worker_id' => $workerA->id, 'event' => 'complete']);
        $this->createAuditLog(['worker_id' => $workerB->id, 'event' => 'poll']);

        $response = $this->adminGet("/api/admin/workers/{$workerA->id}/audit-logs");
        $response->assertStatus(200);

        $this->assertSame(2, $response->json('data.totalItems'));
    }

    public function test_workers_audit_logs_returns_404_for_missing_worker(): void
    {
        $this->adminGet('/api/admin/workers/99999/audit-logs')->assertStatus(404);
    }

    public function test_store_creates_worker_and_returns_token(): void
    {
        $response = $this->adminPost('/api/admin/workers', [
            'worker_id' => 'new-gpu-node',
            'display_name' => 'New GPU Node',
        ]);

        $response->assertStatus(201);

        $token = $response->json('data.token');
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token));

        $worker = ComfyUiWorker::query()->where('worker_id', 'new-gpu-node')->first();
        $this->assertNotNull($worker);
        $this->assertSame('New GPU Node', $worker->display_name);
        $this->assertFalse($worker->is_approved);
        $this->assertSame(hash('sha256', $token), $worker->token_hash);

        // Verify audit log
        $log = WorkerAuditLog::query()
            ->where('worker_id', $worker->id)
            ->where('event', 'created')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_store_rejects_duplicate_worker_id(): void
    {
        $this->createWorker(['worker_id' => 'dup-worker']);

        $response = $this->adminPost('/api/admin/workers', [
            'worker_id' => 'dup-worker',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_requires_admin(): void
    {
        $this->postJson('/api/admin/workers', [
            'worker_id' => 'unauth-worker',
        ])->assertStatus(401);

        $nonAdmin = User::factory()->create(['is_admin' => false]);
        Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $nonAdmin->id,
            'db_pool' => 'tenant_pool_1',
        ]);
        Sanctum::actingAs($nonAdmin);
        $this->postJson('/api/admin/workers', [
            'worker_id' => 'unauth-worker',
        ])->assertStatus(403);
    }
}
