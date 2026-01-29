<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\ComfyUiWorker;
use App\Models\Effect;
use App\Models\Tenant;
use App\Models\User;
use App\Models\File;
use App\Services\PresignedUrlService;
use App\Services\TokenLedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class ComfyUiWorkerDispatchTest extends TestCase
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

        config(['services.comfyui.worker_token' => 'test-token']);
        config(['services.comfyui.presigned_ttl_seconds' => 60]);
        config(['services.comfyui.max_attempts' => 3]);

        app()->instance(PresignedUrlService::class, new class extends PresignedUrlService {
            public function downloadUrl(string $disk, string $path, int $ttlSeconds): string
            {
                return 'https://example.com/input';
            }

            public function uploadUrl(string $disk, string $path, int $ttlSeconds, ?string $contentType = null): array
            {
                return ['url' => 'https://example.com/output', 'headers' => []];
            }
        });
    }

    public function test_poll_leases_job_once(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $first = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-1',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $first->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job.dispatch_id', $dispatch->id);

        $second = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-2',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $second->assertStatus(200)
            ->assertJsonPath('data.job', null);

        $dispatch->refresh();
        $this->assertSame('leased', $dispatch->status);
        $this->assertSame('worker-1', $dispatch->worker_id);
        $this->assertNotEmpty($dispatch->lease_token);
    }

    public function test_poll_releases_expired_lease(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'leased',
            'priority' => 0,
            'attempts' => 1,
            'lease_token' => 'old-token',
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-1',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job.dispatch_id', $dispatch->id);

        $dispatch->refresh();
        $this->assertSame('leased', $dispatch->status);
        $this->assertSame('worker-1', $dispatch->worker_id);
        $this->assertNotSame('old-token', $dispatch->lease_token);
        $this->assertSame(2, $dispatch->attempts);
    }

    public function test_complete_is_idempotent_and_consumes_once(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->seedWallet($tenant->id, $user->id, 25);
        $this->reserveTokens($tenant, $job, 5);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-1',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $poll->assertStatus(200);
        $leaseToken = $poll->json('data.job.lease_token');
        $dispatchId = $poll->json('data.job.dispatch_id');

        $completePayload = [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-1',
            'provider_job_id' => 'prompt-123',
            'output' => [
                'size' => 123,
                'mime_type' => 'video/mp4',
            ],
        ];

        $first = $this->postJson('/api/worker/complete', $completePayload, [
            'Authorization' => 'Bearer test-token',
        ]);
        $first->assertStatus(200);

        $second = $this->postJson('/api/worker/complete', $completePayload, [
            'Authorization' => 'Bearer test-token',
        ]);
        $second->assertStatus(200);

        $this->assertSame(1, $this->getJobTransactionCount($tenant->id, $job->id, 'JOB_CONSUME'));

        $job = $this->fetchTenantJob($tenant, $job->id);
        $this->assertSame('completed', $job->status);
    }

    public function test_poll_respects_worker_capacity(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-capacity',
            'current_load' => 1,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job', null);
    }

    public function test_poll_respects_draining_worker(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        ComfyUiWorker::query()->create([
            'worker_id' => 'worker-drain',
            'environment' => 'cloud',
            'max_concurrency' => 1,
            'current_load' => 0,
            'is_draining' => true,
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-drain',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job', null);
    }

    public function test_poll_returns_presigned_urls(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-urls',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job.input_url', 'https://example.com/input')
            ->assertJsonPath('data.job.output_url', 'https://example.com/output');
    }

    public function test_heartbeat_updates_worker_last_seen(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-heartbeat',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $leaseToken = $poll->json('data.job.lease_token');
        $dispatchId = $poll->json('data.job.dispatch_id');

        $before = AiJobDispatch::query()->find($dispatchId);

        $this->postJson('/api/worker/heartbeat', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-heartbeat',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $after = AiJobDispatch::query()->find($dispatchId);
        $worker = ComfyUiWorker::query()->where('worker_id', 'worker-heartbeat')->first();
        $this->assertNotNull($worker?->last_seen_at);
        $this->assertNotNull($after?->lease_expires_at);
        $this->assertTrue($after->lease_expires_at->greaterThan($before->lease_expires_at));
    }

    public function test_invalid_lease_token_rejected(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $this->postJson('/api/worker/heartbeat', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'invalid',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(404);

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'invalid',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(404);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'invalid',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(404);
    }

    public function test_fail_refunds_once_and_sets_error(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->seedWallet($tenant->id, $user->id, 10);
        $this->reserveTokens($tenant, $job, 5);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-fail',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail',
            'error_message' => 'boom',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail',
            'error_message' => 'boom',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $this->assertSame(1, $this->getJobTransactionCount($tenant->id, $job->id, 'JOB_REFUND'));

        $dispatch = AiJobDispatch::query()->find($dispatchId);
        $this->assertSame('failed', $dispatch?->status);
        $this->assertSame('boom', $dispatch?->last_error);

        $job = $this->fetchTenantJob($tenant, $job->id);
        $this->assertSame('failed', $job->status);
        $this->assertSame('boom', $job->error_message);
    }

    public function test_fail_does_not_override_completed(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->seedWallet($tenant->id, $user->id, 10);
        $this->reserveTokens($tenant, $job, 5);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-complete',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-complete',
            'provider_job_id' => 'prompt-1',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-complete',
            'error_message' => 'late-fail',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $job = $this->fetchTenantJob($tenant, $job->id);
        $this->assertSame('completed', $job->status);
        $this->assertSame(0, $this->getJobTransactionCount($tenant->id, $job->id, 'JOB_REFUND'));
    }

    public function test_complete_does_not_override_failed(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->seedWallet($tenant->id, $user->id, 10);
        $this->reserveTokens($tenant, $job, 5);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-fail-first',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail-first',
            'error_message' => 'fail-first',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail-first',
            'provider_job_id' => 'prompt-late',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $job = $this->fetchTenantJob($tenant, $job->id);
        $this->assertSame('failed', $job->status);
        $this->assertSame(0, $this->getJobTransactionCount($tenant->id, $job->id, 'JOB_CONSUME'));
    }

    public function test_complete_updates_output_file_metadata(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $inputFileId = $this->createTenantFile($tenant->id, $user->id);
        $outputFileId = $this->createTenantFile($tenant->id, $user->id);

        $job = $this->createTenantJob($tenant, $user, $effect, $inputFileId);
        $this->setJobOutputFile($tenant, $job, $outputFileId);

        $this->seedWallet($tenant->id, $user->id, 10);
        $this->reserveTokens($tenant, $job, 5);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-output',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-output',
            'provider_job_id' => 'prompt-2',
            'output' => [
                'size' => 999,
                'mime_type' => 'video/mp4',
            ],
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $file = $this->fetchTenantFile($tenant, $outputFileId);
        $this->assertSame(999, $file->size);
        $this->assertSame('video/mp4', $file->mime_type);
        $this->assertNotEmpty($file->url);
    }

    public function test_complete_without_output_file_still_completes(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->seedWallet($tenant->id, $user->id, 10);
        $this->reserveTokens($tenant, $job, 5);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-no-output',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-no-output',
            'provider_job_id' => 'prompt-3',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $job = $this->fetchTenantJob($tenant, $job->id);
        $this->assertSame('completed', $job->status);
    }

    public function test_orphan_dispatch_marked_failed(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $this->createEffect();

        $dispatch = AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => 999999,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-orphan',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job', null);

        $dispatch->refresh();
        $this->assertSame('failed', $dispatch->status);
        $this->assertNotEmpty($dispatch->last_error);
    }

    public function test_dispatch_priority_ordering(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);

        $lowJob = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $highJob = $this->createTenantJob($tenant, $user, $effect, $fileId);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $lowJob->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $highJob->id,
            'status' => 'queued',
            'priority' => 5,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-priority',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $poll->assertStatus(200)
            ->assertJsonPath('data.job.job_id', $highJob->id);
    }

    public function test_poll_skips_dispatch_after_max_attempts(): void
    {
        config(['services.comfyui.max_attempts' => 1]);

        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_job_id' => $job->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 1,
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-max-attempts',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job', null);
    }

    public function test_worker_auth_required(): void
    {
        $this->postJson('/api/worker/poll', [
            'worker_id' => 'unauth',
            'current_load' => 0,
            'max_concurrency' => 1,
        ])->assertStatus(401);
    }

    public function test_worker_auth_rejects_invalid_token(): void
    {
        $this->postJson('/api/worker/poll', [
            'worker_id' => 'invalid-token',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);
    }

    public function test_tenant_isolation_does_not_touch_other_tenant(): void
    {
        [$userA, $tenantA] = $this->createUserTenant();
        [$userB, $tenantB] = $this->createUserTenant();

        $effect = $this->createEffect();
        $fileA = $this->createTenantFile($tenantA->id, $userA->id);
        $fileB = $this->createTenantFile($tenantB->id, $userB->id);

        $jobA = $this->createTenantJob($tenantA, $userA, $effect, $fileA);
        $jobB = $this->createTenantJob($tenantB, $userB, $effect, $fileB);

        AiJobDispatch::query()->create([
            'tenant_id' => $tenantA->id,
            'tenant_job_id' => $jobA->id,
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
        ]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-isolation',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer test-token',
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-isolation',
            'provider_job_id' => 'prompt-iso',
        ], [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(200);

        $jobB = $this->fetchTenantJob($tenantB, $jobB->id);
        $this->assertSame('queued', $jobB->status);
    }

    private function createUserTenant(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'db_pool' => 'tenant_pool_1',
        ]);

        return [$user, $tenant];
    }

    private function createEffect(): Effect
    {
        return Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => 5,
            'processing_time_estimate' => 10,
            'popularity_score' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
        ]);
    }

    private function createTenantJob(Tenant $tenant, User $user, Effect $effect, ?int $inputFileId = null, ?int $videoId = null): AiJob
    {
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            return AiJob::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'effect_id' => $effect->id,
                'video_id' => $videoId,
                'input_file_id' => $inputFileId,
                'status' => 'queued',
                'idempotency_key' => 'job_' . uniqid(),
                'requested_tokens' => 5,
                'reserved_tokens' => 0,
                'consumed_tokens' => 0,
                'input_payload' => ['output_extension' => 'mp4'],
            ]);
        } finally {
            $tenancy->end();
        }
    }

    private function createTenantFile(string $tenantId, int $userId): int
    {
        return (int) DB::connection('tenant_pool_1')->table('files')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'disk' => 'local',
            'path' => 'uploads/' . uniqid() . '.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1234,
            'original_filename' => 'input.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reserveTokens(Tenant $tenant, AiJob $job, int $amount): void
    {
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            app(TokenLedgerService::class)->reserveForJob($job, $amount, ['source' => 'test']);
        } finally {
            $tenancy->end();
        }
    }

    private function seedWallet(string $tenantId, int $userId, int $balance): void
    {
        DB::connection('tenant_pool_1')->table('token_wallets')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'balance' => $balance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function getJobTransactionCount(string $tenantId, int $jobId, string $type): int
    {
        return (int) DB::connection('tenant_pool_1')
            ->table('token_transactions')
            ->where('tenant_id', $tenantId)
            ->where('job_id', $jobId)
            ->where('type', $type)
            ->count();
    }

    private function fetchTenantJob(Tenant $tenant, int $jobId): AiJob
    {
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            return AiJob::query()->findOrFail($jobId);
        } finally {
            $tenancy->end();
        }
    }

    private function setJobOutputFile(Tenant $tenant, AiJob $job, int $fileId): void
    {
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            AiJob::query()->whereKey($job->id)->update([
                'output_file_id' => $fileId,
            ]);
        } finally {
            $tenancy->end();
        }
    }

    private function fetchTenantFile(Tenant $tenant, int $fileId): File
    {
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            return File::query()->findOrFail($fileId);
        } finally {
            $tenancy->end();
        }
    }
}
