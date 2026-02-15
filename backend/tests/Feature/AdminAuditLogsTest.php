<?php

namespace Tests\Feature;

use App\Models\ComfyUiWorker;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkerAuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuditLogsTest extends TestCase
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
        DB::connection('central')->table('worker_audit_logs')->truncate();
        DB::connection('central')->table('comfy_ui_workers')->truncate();
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
        ];

        return ComfyUiWorker::query()->create(array_merge($defaults, $overrides));
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

    public function test_audit_logs_index_requires_admin(): void
    {
        $this->getJson('/api/admin/audit-logs')->assertStatus(401);

        $nonAdmin = User::factory()->create(['is_admin' => false]);
        Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $nonAdmin->id,
            'db_pool' => 'tenant_pool_1',
        ]);
        Sanctum::actingAs($nonAdmin);
        $this->getJson('/api/admin/audit-logs')->assertStatus(403);
    }

    public function test_audit_logs_index_returns_paginated_list(): void
    {
        $worker = $this->createWorker(['display_name' => 'My Worker']);
        $this->createAuditLog(['worker_id' => $worker->id, 'event' => 'poll']);
        $this->createAuditLog(['worker_id' => $worker->id, 'event' => 'complete']);

        $response = $this->adminGet('/api/admin/audit-logs');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('items', $data);
        $this->assertSame(2, $data['totalItems']);

        // Check that worker_display_name is present from join
        $firstItem = $data['items'][0];
        $this->assertArrayHasKey('worker_display_name', $firstItem);
        $this->assertSame('My Worker', $firstItem['worker_display_name']);
    }

    public function test_audit_logs_index_search_by_worker_identifier_and_event(): void
    {
        $this->createAuditLog(['worker_identifier' => 'gpu-node-1', 'event' => 'poll', 'ip_address' => '10.0.0.1']);
        $this->createAuditLog(['worker_identifier' => 'cpu-node-2', 'event' => 'complete', 'ip_address' => '10.0.0.2']);

        $response = $this->adminGet('/api/admin/audit-logs', ['search' => 'gpu-node']);
        $this->assertSame(1, $response->json('data.totalItems'));

        $response2 = $this->adminGet('/api/admin/audit-logs', ['search' => 'complete']);
        $this->assertSame(1, $response2->json('data.totalItems'));

        $response3 = $this->adminGet('/api/admin/audit-logs', ['search' => '10.0.0.1']);
        $this->assertSame(1, $response3->json('data.totalItems'));
    }

    public function test_audit_logs_index_filters_by_event(): void
    {
        $this->createAuditLog(['event' => 'poll']);
        $this->createAuditLog(['event' => 'complete']);
        $this->createAuditLog(['event' => 'fail']);

        $response = $this->adminGet('/api/admin/audit-logs', ['event' => 'poll,complete']);
        $this->assertSame(2, $response->json('data.totalItems'));
    }

    public function test_audit_logs_index_filters_by_worker_id(): void
    {
        $workerA = $this->createWorker();
        $workerB = $this->createWorker();

        $this->createAuditLog(['worker_id' => $workerA->id, 'event' => 'poll']);
        $this->createAuditLog(['worker_id' => $workerA->id, 'event' => 'complete']);
        $this->createAuditLog(['worker_id' => $workerB->id, 'event' => 'poll']);

        $response = $this->adminGet('/api/admin/audit-logs', ['worker_id' => $workerA->id]);
        $this->assertSame(2, $response->json('data.totalItems'));
    }

    public function test_audit_logs_index_filters_by_date_range(): void
    {
        $this->createAuditLog(['event' => 'old', 'created_at' => now()->subDays(10)]);
        $this->createAuditLog(['event' => 'recent', 'created_at' => now()->subDay()]);
        $this->createAuditLog(['event' => 'today', 'created_at' => now()]);

        $fromDate = now()->subDays(2)->toDateTimeString();
        $toDate = now()->addMinute()->toDateTimeString();

        $response = $this->adminGet('/api/admin/audit-logs', [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        $this->assertSame(2, $response->json('data.totalItems'));
    }

    public function test_audit_logs_index_ordering(): void
    {
        $this->createAuditLog(['event' => 'complete', 'created_at' => now()->subMinute()]);
        $this->createAuditLog(['event' => 'fail', 'created_at' => now()]);
        $this->createAuditLog(['event' => 'approve', 'created_at' => now()->addMinute()]);

        $response = $this->adminGet('/api/admin/audit-logs', ['order' => 'event:asc']);
        $events = collect($response->json('data.items'))->pluck('event')->toArray();
        $this->assertSame('approve', $events[0]);

        // Invalid field falls back to created_at
        $response2 = $this->adminGet('/api/admin/audit-logs', ['order' => 'invalid_field:desc']);
        $response2->assertStatus(200);
    }

    public function test_audit_logs_index_caps_per_page_at_100(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createAuditLog(['event' => "event-{$i}"]);
        }

        $response = $this->adminGet('/api/admin/audit-logs', ['perPage' => 200]);
        $response->assertStatus(200);
        $this->assertSame(100, $response->json('data.perPage'));
    }

    public function test_audit_logs_shows_null_display_name_for_deleted_worker(): void
    {
        // Create a log with a worker_id pointing to nonexistent worker
        $this->createAuditLog(['worker_id' => 99999, 'event' => 'poll']);

        $response = $this->adminGet('/api/admin/audit-logs');
        $response->assertStatus(200);

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertNull($items[0]['worker_display_name']);
    }
}
