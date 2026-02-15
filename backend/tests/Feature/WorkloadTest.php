<?php

namespace Tests\Feature;

use App\Http\Controllers\ComfyUiWorkerController;
use App\Models\AiJobDispatch;
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

class WorkloadTest extends TestCase
{
    protected static bool $prepared = false;

    private User $adminUser;
    private Tenant $tenant;
    private static int $tenantJobSeq = 0;

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

        // Use a non-central domain so PreventAccessFromCentralDomains passes.
        // SymfonyRequest::create() extracts the host from the URL, overriding
        // any Host header, so we must set app.url to a tenant domain.
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
        DB::connection('central')->table('ai_job_dispatches')->truncate();
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

    private function adminPut(string $uri, array $data = [])
    {
        $this->actAsAdmin();

        return $this->putJson($uri, $data);
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

    private function createDispatch(array $overrides = []): AiJobDispatch
    {
        $defaults = [
            'tenant_id' => $this->tenant->id,
            'tenant_job_id' => ++static::$tenantJobSeq,
            'provider' => 'self_hosted',
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ];

        return AiJobDispatch::query()->create(array_merge($defaults, $overrides));
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

    private function assignWorkerToWorkflow(int $workerId, int $workflowId): void
    {
        DB::connection('central')->table('worker_workflows')->insert([
            'worker_id' => $workerId,
            'workflow_id' => $workflowId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ========================================================================
    // A. Auth & Access Control
    // ========================================================================

    public function test_workload_index_requires_authentication(): void
    {
        $this->getJson('/api/admin/workload')->assertStatus(401);
    }

    public function test_workload_index_requires_admin_role(): void
    {
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $nonAdmin->id,
            'db_pool' => 'tenant_pool_1',
        ]);
        Sanctum::actingAs($nonAdmin);

        $this->getJson('/api/admin/workload')->assertStatus(403);
    }

    public function test_assign_workers_requires_authentication(): void
    {
        $this->putJson('/api/admin/workload/workflows/1/workers')->assertStatus(401);
    }

    public function test_assign_workers_requires_admin_role(): void
    {
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $nonAdmin->id,
            'db_pool' => 'tenant_pool_1',
        ]);
        Sanctum::actingAs($nonAdmin);

        $this->putJson('/api/admin/workload/workflows/1/workers', ['worker_ids' => []])
            ->assertStatus(403);
    }

    // ========================================================================
    // B. Workload Index — Validation
    // ========================================================================

    public function test_workload_index_rejects_invalid_period(): void
    {
        $this->adminGet('/api/admin/workload', ['period' => '2h'])->assertStatus(422);
        $this->adminGet('/api/admin/workload', ['period' => 'abc'])->assertStatus(422);
    }

    public function test_workload_index_accepts_valid_periods(): void
    {
        $this->adminGet('/api/admin/workload', ['period' => '24h'])->assertStatus(200);
        $this->adminGet('/api/admin/workload', ['period' => '7d'])->assertStatus(200);
        $this->adminGet('/api/admin/workload', ['period' => '30d'])->assertStatus(200);
    }

    public function test_workload_index_defaults_to_24h_when_no_period(): void
    {
        $this->adminGet('/api/admin/workload')->assertStatus(200);
    }

    // ========================================================================
    // C. Workload Index — Response Structure
    // ========================================================================

    public function test_workload_index_returns_all_workflows(): void
    {
        $this->createWorkflow(['name' => 'WF1', 'slug' => 'wf1']);
        $this->createWorkflow(['name' => 'WF2', 'slug' => 'wf2']);
        $this->createWorkflow(['name' => 'WF3', 'slug' => 'wf3']);

        $response = $this->adminGet('/api/admin/workload');
        $response->assertStatus(200);

        $workflows = $response->json('data.workflows');
        $this->assertCount(3, $workflows);

        foreach ($workflows as $wf) {
            $this->assertArrayHasKey('id', $wf);
            $this->assertArrayHasKey('name', $wf);
            $this->assertArrayHasKey('slug', $wf);
            $this->assertArrayHasKey('is_active', $wf);
            $this->assertArrayHasKey('stats', $wf);
            $this->assertArrayHasKey('worker_ids', $wf);
        }
    }

    public function test_workload_index_returns_all_workers(): void
    {
        $this->createWorker(['worker_id' => 'w1', 'display_name' => 'Worker 1']);
        $this->createWorker(['worker_id' => 'w2', 'display_name' => 'Worker 2']);

        $response = $this->adminGet('/api/admin/workload');
        $response->assertStatus(200);

        $workers = $response->json('data.workers');
        $this->assertCount(2, $workers);

        foreach ($workers as $worker) {
            $this->assertArrayHasKey('id', $worker);
            $this->assertArrayHasKey('worker_id', $worker);
            $this->assertArrayHasKey('display_name', $worker);
            $this->assertArrayHasKey('is_approved', $worker);
            $this->assertArrayHasKey('is_draining', $worker);
            $this->assertArrayHasKey('current_load', $worker);
            $this->assertArrayHasKey('max_concurrency', $worker);
            $this->assertArrayHasKey('last_seen_at', $worker);
            $this->assertArrayNotHasKey('token_hash', $worker);
        }
    }

    public function test_workload_index_returns_empty_arrays_when_no_data(): void
    {
        $response = $this->adminGet('/api/admin/workload');

        $response->assertStatus(200)
            ->assertJsonPath('data.workflows', [])
            ->assertJsonPath('data.workers', []);
    }

    // ========================================================================
    // D. Workload Index — Stats: Queued & Processing (not period-filtered)
    // ========================================================================

    public function test_stats_count_queued_dispatches_per_workflow(): void
    {
        $wfA = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $wfB = $this->createWorkflow(['name' => 'B', 'slug' => 'b']);

        $this->createDispatch(['workflow_id' => $wfA->id, 'status' => 'queued']);
        $this->createDispatch(['workflow_id' => $wfA->id, 'status' => 'queued']);
        $this->createDispatch(['workflow_id' => $wfA->id, 'status' => 'queued']);
        $this->createDispatch(['workflow_id' => $wfB->id, 'status' => 'queued']);

        $response = $this->adminGet('/api/admin/workload');
        $response->assertStatus(200);

        $workflows = collect($response->json('data.workflows'));
        $this->assertSame(3, $workflows->firstWhere('id', $wfA->id)['stats']['queued']);
        $this->assertSame(1, $workflows->firstWhere('id', $wfB->id)['stats']['queued']);
    }

    public function test_stats_count_processing_dispatches_per_workflow(): void
    {
        $wfA = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wfA->id, 'status' => 'leased']);
        $this->createDispatch(['workflow_id' => $wfA->id, 'status' => 'leased']);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(2, $workflows->firstWhere('id', $wfA->id)['stats']['processing']);
    }

    public function test_queued_and_processing_are_not_period_filtered(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $dispatch = $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'queued']);
        AiJobDispatch::query()->whereKey($dispatch->id)->update(['updated_at' => now()->subDays(2)]);

        $response = $this->adminGet('/api/admin/workload', ['period' => '24h']);
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(1, $workflows->firstWhere('id', $wf->id)['stats']['queued']);
    }

    public function test_stats_zero_when_no_dispatches(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));
        $stats = $workflows->firstWhere('id', $wf->id)['stats'];

        $this->assertSame(0, $stats['queued']);
        $this->assertSame(0, $stats['processing']);
        $this->assertSame(0, $stats['completed']);
        $this->assertSame(0, $stats['failed']);
    }

    // ========================================================================
    // E. Workload Index — Stats: Completed & Failed (period-filtered)
    // ========================================================================

    public function test_stats_count_completed_within_period(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed']);
        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed']);

        $old = $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed']);
        AiJobDispatch::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(3)]);

        $response = $this->adminGet('/api/admin/workload', ['period' => '24h']);
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(2, $workflows->firstWhere('id', $wf->id)['stats']['completed']);
    }

    public function test_stats_count_failed_within_period(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'failed']);

        $old = $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'failed']);
        AiJobDispatch::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(8)]);

        $response = $this->adminGet('/api/admin/workload', ['period' => '7d']);
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(1, $workflows->firstWhere('id', $wf->id)['stats']['failed']);
    }

    public function test_completed_outside_period_excluded(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $old = $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed']);
        AiJobDispatch::query()->whereKey($old->id)->update(['updated_at' => now()->subHours(25)]);

        $response = $this->adminGet('/api/admin/workload', ['period' => '24h']);
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(0, $workflows->firstWhere('id', $wf->id)['stats']['completed']);
    }

    public function test_30d_period_includes_older_dispatches(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $old = $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed']);
        AiJobDispatch::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(20)]);

        $response30d = $this->adminGet('/api/admin/workload', ['period' => '30d']);
        $workflows30d = collect($response30d->json('data.workflows'));
        $this->assertSame(1, $workflows30d->firstWhere('id', $wf->id)['stats']['completed']);

        $response7d = $this->adminGet('/api/admin/workload', ['period' => '7d']);
        $workflows7d = collect($response7d->json('data.workflows'));
        $this->assertSame(0, $workflows7d->firstWhere('id', $wf->id)['stats']['completed']);
    }

    // ========================================================================
    // F. Workload Index — Stats: Duration
    // ========================================================================

    public function test_avg_duration_calculated_for_completed_with_duration(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 30]);
        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 60]);
        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 90]);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(60, $workflows->firstWhere('id', $wf->id)['stats']['avg_duration_seconds']);
    }

    public function test_avg_duration_null_when_no_completed_with_duration(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => null]);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertNull($workflows->firstWhere('id', $wf->id)['stats']['avg_duration_seconds']);
    }

    public function test_avg_duration_ignores_null_durations(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 100]);
        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => null]);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(100, $workflows->firstWhere('id', $wf->id)['stats']['avg_duration_seconds']);
    }

    public function test_total_duration_sums_completed_durations(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 100]);
        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 200]);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame(300, $workflows->firstWhere('id', $wf->id)['stats']['total_duration_seconds']);
    }

    public function test_total_duration_null_when_no_durations(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => null]);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertNull($workflows->firstWhere('id', $wf->id)['stats']['total_duration_seconds']);
    }

    public function test_duration_stats_are_period_filtered(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 100]);

        $old = $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'completed', 'duration_seconds' => 200]);
        AiJobDispatch::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);

        $response = $this->adminGet('/api/admin/workload', ['period' => '24h']);
        $workflows = collect($response->json('data.workflows'));
        $stats = $workflows->firstWhere('id', $wf->id)['stats'];

        $this->assertSame(100, $stats['avg_duration_seconds']);
        $this->assertSame(100, $stats['total_duration_seconds']);
    }

    // ========================================================================
    // G. Workload Index — Worker Assignments
    // ========================================================================

    public function test_workflow_returns_assigned_worker_ids(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $w1 = $this->createWorker(['worker_id' => 'w1']);
        $w3 = $this->createWorker(['worker_id' => 'w3']);

        $this->assignWorkerToWorkflow($w1->id, $wf->id);
        $this->assignWorkerToWorkflow($w3->id, $wf->id);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));
        $workerIds = $workflows->firstWhere('id', $wf->id)['worker_ids'];
        sort($workerIds);

        $this->assertSame([$w1->id, $w3->id], $workerIds);
    }

    public function test_workflow_returns_empty_array_when_no_workers_assigned(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertSame([], $workflows->firstWhere('id', $wf->id)['worker_ids']);
    }

    public function test_worker_assignment_reflects_many_to_many(): void
    {
        $wfA = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $wfB = $this->createWorkflow(['name' => 'B', 'slug' => 'b']);
        $w1 = $this->createWorker(['worker_id' => 'w1']);

        $this->assignWorkerToWorkflow($w1->id, $wfA->id);
        $this->assignWorkerToWorkflow($w1->id, $wfB->id);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertContains($w1->id, $workflows->firstWhere('id', $wfA->id)['worker_ids']);
        $this->assertContains($w1->id, $workflows->firstWhere('id', $wfB->id)['worker_ids']);
    }

    // ========================================================================
    // H. Workload Index — Edge Cases
    // ========================================================================

    public function test_inactive_workflows_included_in_response(): void
    {
        $active = $this->createWorkflow(['name' => 'Active', 'slug' => 'active', 'is_active' => true]);
        $inactive = $this->createWorkflow(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);

        $response = $this->adminGet('/api/admin/workload');
        $workflows = collect($response->json('data.workflows'));

        $this->assertCount(2, $workflows);
        $this->assertTrue($workflows->firstWhere('id', $active->id)['is_active']);
        $this->assertFalse($workflows->firstWhere('id', $inactive->id)['is_active']);
    }

    public function test_dispatches_with_null_workflow_id_not_crash_stats(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $this->createDispatch(['workflow_id' => $wf->id, 'status' => 'queued']);
        $this->createDispatch(['workflow_id' => null, 'status' => 'queued']);

        $response = $this->adminGet('/api/admin/workload');
        $response->assertStatus(200);

        $workflows = collect($response->json('data.workflows'));
        $this->assertSame(1, $workflows->firstWhere('id', $wf->id)['stats']['queued']);
    }

    public function test_workflows_ordered_by_name(): void
    {
        $this->createWorkflow(['name' => 'Zebra', 'slug' => 'zebra']);
        $this->createWorkflow(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->createWorkflow(['name' => 'Middle', 'slug' => 'middle']);

        $response = $this->adminGet('/api/admin/workload');
        $names = collect($response->json('data.workflows'))->pluck('name')->toArray();

        $this->assertSame(['Alpha', 'Middle', 'Zebra'], $names);
    }

    public function test_workers_ordered_by_worker_id(): void
    {
        $this->createWorker(['worker_id' => 'z-worker']);
        $this->createWorker(['worker_id' => 'a-worker']);

        $response = $this->adminGet('/api/admin/workload');
        $workerIds = collect($response->json('data.workers'))->pluck('worker_id')->toArray();

        $this->assertSame(['a-worker', 'z-worker'], $workerIds);
    }

    // ========================================================================
    // I. Assign Workers — Validation
    // ========================================================================

    public function test_assign_workers_returns_404_for_nonexistent_workflow(): void
    {
        $this->adminPut('/api/admin/workload/workflows/99999/workers', ['worker_ids' => []])
            ->assertStatus(404);
    }

    public function test_assign_workers_requires_worker_ids_array(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", [])
            ->assertStatus(422);

        $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", ['worker_ids' => 'string'])
            ->assertStatus(422);
    }

    public function test_assign_workers_rejects_nonexistent_worker_id(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);

        $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", ['worker_ids' => [99999]])
            ->assertStatus(422);
    }

    // ========================================================================
    // J. Assign Workers — Behavior
    // ========================================================================

    public function test_assign_workers_adds_workers_to_workflow(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $w1 = $this->createWorker(['worker_id' => 'w1']);
        $w2 = $this->createWorker(['worker_id' => 'w2']);

        $response = $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", [
            'worker_ids' => [$w1->id, $w2->id],
        ]);
        $response->assertStatus(200);

        $workerIds = $response->json('data.worker_ids');
        sort($workerIds);
        $this->assertSame([$w1->id, $w2->id], $workerIds);

        $pivotCount = DB::connection('central')->table('worker_workflows')
            ->where('workflow_id', $wf->id)
            ->count();
        $this->assertSame(2, $pivotCount);
    }

    public function test_assign_workers_removes_unassigned_workers(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $w1 = $this->createWorker(['worker_id' => 'w1']);
        $w2 = $this->createWorker(['worker_id' => 'w2']);

        $this->assignWorkerToWorkflow($w1->id, $wf->id);
        $this->assignWorkerToWorkflow($w2->id, $wf->id);

        $response = $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", [
            'worker_ids' => [$w2->id],
        ]);
        $response->assertStatus(200);
        $this->assertSame([$w2->id], $response->json('data.worker_ids'));

        $this->assertSame(0, DB::connection('central')->table('worker_workflows')
            ->where('workflow_id', $wf->id)
            ->where('worker_id', $w1->id)
            ->count());
    }

    public function test_assign_workers_with_empty_array_clears_all(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $w1 = $this->createWorker(['worker_id' => 'w1']);
        $w2 = $this->createWorker(['worker_id' => 'w2']);

        $this->assignWorkerToWorkflow($w1->id, $wf->id);
        $this->assignWorkerToWorkflow($w2->id, $wf->id);

        $response = $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", [
            'worker_ids' => [],
        ]);
        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.worker_ids'));
    }

    public function test_assign_workers_is_idempotent(): void
    {
        $wf = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $w1 = $this->createWorker(['worker_id' => 'w1']);
        $w2 = $this->createWorker(['worker_id' => 'w2']);

        $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", [
            'worker_ids' => [$w1->id, $w2->id],
        ])->assertStatus(200);

        $response = $this->adminPut("/api/admin/workload/workflows/{$wf->id}/workers", [
            'worker_ids' => [$w1->id, $w2->id],
        ]);
        $response->assertStatus(200);

        $workerIds = $response->json('data.worker_ids');
        sort($workerIds);
        $this->assertSame([$w1->id, $w2->id], $workerIds);

        $pivotCount = DB::connection('central')->table('worker_workflows')
            ->where('workflow_id', $wf->id)
            ->count();
        $this->assertSame(2, $pivotCount);
    }

    public function test_assign_workers_does_not_affect_other_workflows(): void
    {
        $wfA = $this->createWorkflow(['name' => 'A', 'slug' => 'a']);
        $wfB = $this->createWorkflow(['name' => 'B', 'slug' => 'b']);
        $w1 = $this->createWorker(['worker_id' => 'w1']);

        $this->assignWorkerToWorkflow($w1->id, $wfA->id);
        $this->assignWorkerToWorkflow($w1->id, $wfB->id);

        $this->adminPut("/api/admin/workload/workflows/{$wfA->id}/workers", [
            'worker_ids' => [],
        ])->assertStatus(200);

        $bPivot = DB::connection('central')->table('worker_workflows')
            ->where('workflow_id', $wfB->id)
            ->where('worker_id', $w1->id)
            ->count();
        $this->assertSame(1, $bPivot);
    }

    // ========================================================================
    // K. Duration Computation on Job Completion
    // ========================================================================

    public function test_mark_dispatch_completed_computes_duration_from_poll_event(): void
    {
        $dispatch = $this->createDispatch(['status' => 'leased', 'worker_id' => 'w1']);

        $this->createAuditLog([
            'dispatch_id' => $dispatch->id,
            'event' => 'poll',
            'created_at' => now()->subSeconds(60),
        ]);

        $this->callMarkDispatchCompleted($dispatch, 'w1');

        $dispatch->refresh();
        $this->assertSame('completed', $dispatch->status);
        $this->assertNotNull($dispatch->duration_seconds);
        $this->assertEqualsWithDelta(60, $dispatch->duration_seconds, 2);
    }

    public function test_mark_dispatch_completed_uses_earliest_poll_event(): void
    {
        $dispatch = $this->createDispatch(['status' => 'leased', 'worker_id' => 'w1']);

        $this->createAuditLog([
            'dispatch_id' => $dispatch->id,
            'event' => 'poll',
            'created_at' => now()->subSeconds(120),
        ]);
        $this->createAuditLog([
            'dispatch_id' => $dispatch->id,
            'event' => 'poll',
            'created_at' => now()->subSeconds(60),
        ]);

        $this->callMarkDispatchCompleted($dispatch, 'w1');

        $dispatch->refresh();
        $this->assertEqualsWithDelta(120, $dispatch->duration_seconds, 2);
    }

    public function test_mark_dispatch_completed_no_duration_when_no_poll_log(): void
    {
        $dispatch = $this->createDispatch(['status' => 'leased', 'worker_id' => 'w1']);

        $this->callMarkDispatchCompleted($dispatch, 'w1');

        $dispatch->refresh();
        $this->assertNull($dispatch->duration_seconds);
    }

    public function test_mark_dispatch_completed_sets_status_and_clears_lease(): void
    {
        $dispatch = $this->createDispatch([
            'status' => 'leased',
            'worker_id' => 'w1',
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        $this->callMarkDispatchCompleted($dispatch, 'w1');

        $dispatch->refresh();
        $this->assertSame('completed', $dispatch->status);
        $this->assertNull($dispatch->lease_expires_at);
    }

    public function test_mark_dispatch_completed_preserves_worker_id_when_null_passed(): void
    {
        $dispatch = $this->createDispatch(['status' => 'leased', 'worker_id' => 'w1']);

        $this->callMarkDispatchCompleted($dispatch, null);

        $dispatch->refresh();
        $this->assertSame('w1', $dispatch->worker_id);
    }

    /**
     * Calls the private markDispatchCompleted method via Reflection.
     */
    private function callMarkDispatchCompleted(AiJobDispatch $dispatch, ?string $workerId): void
    {
        $controller = app(ComfyUiWorkerController::class);
        $method = new \ReflectionMethod($controller, 'markDispatchCompleted');
        $method->setAccessible(true);
        $method->invoke($controller, $dispatch, $workerId);
    }
}
