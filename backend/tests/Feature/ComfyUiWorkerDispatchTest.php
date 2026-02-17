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
use App\Services\OutputValidationService;
use App\Services\TokenLedgerService;
use App\Models\Workflow;
use App\Models\WorkerAuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class ComfyUiWorkerDispatchTest extends TestCase
{
    protected static bool $prepared = false;
    private string $defaultToken;
    private Workflow $defaultWorkflow;

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

        config(['services.comfyui.default_provider' => 'self_hosted']);
        config(['services.comfyui.presigned_ttl_seconds' => 60]);
        config(['services.comfyui.max_attempts' => 3]);

        $this->resetState();

        $this->defaultToken = 'default-worker-token-' . uniqid();
        ComfyUiWorker::query()->create([
            'worker_id' => 'default-worker',
            'token_hash' => hash('sha256', $this->defaultToken),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 5,
        ]);

        $this->defaultWorkflow = Workflow::query()->create([
            'name' => 'Default Workflow',
            'slug' => 'default-wf-' . uniqid(),
            'is_active' => true,
        ]);

        $defaultWorker = ComfyUiWorker::query()->where('worker_id', 'default-worker')->first();
        $defaultWorker->workflows()->sync([$this->defaultWorkflow->id]);

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

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->table('ai_job_dispatches')->truncate();
        DB::connection('central')->table('comfy_ui_workers')->truncate();
        DB::connection('central')->table('worker_audit_logs')->truncate();
        DB::connection('central')->table('worker_workflows')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');

        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('tenant_pool_1')->table('ai_jobs')->truncate();
        DB::connection('tenant_pool_1')->table('token_transactions')->truncate();
        DB::connection('tenant_pool_1')->table('token_wallets')->truncate();
        DB::connection('tenant_pool_1')->table('files')->truncate();
        DB::connection('tenant_pool_1')->table('videos')->truncate();
        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_poll_leases_job_once(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $dispatch = $this->createDispatch($tenant->id, $job->id);

        $first = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-1',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $first->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job.dispatch_id', $dispatch->id);

        $second = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-2',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $second->assertStatus(200)
            ->assertJsonPath('data.job', null);

        $dispatch->refresh();
        $this->assertSame('leased', $dispatch->status);
        $this->assertSame('default-worker', $dispatch->worker_id);
        $this->assertNotEmpty($dispatch->lease_token);
    }

    public function test_poll_releases_expired_lease(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $dispatch = $this->createDispatch($tenant->id, $job->id, [
            'status' => 'leased',
            'attempts' => 1,
            'lease_token' => 'old-token',
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'default-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.job.dispatch_id', $dispatch->id);

        $dispatch->refresh();
        $this->assertSame('leased', $dispatch->status);
        $this->assertSame('default-worker', $dispatch->worker_id);
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

        $dispatch = $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-1',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);
        $first->assertStatus(200);

        $second = $this->postJson('/api/worker/complete', $completePayload, [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-capacity',
            'current_load' => 1,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $drainToken = 'drain-token-' . uniqid();
        ComfyUiWorker::query()->create([
            'worker_id' => 'worker-drain',
            'token_hash' => hash('sha256', $drainToken),
            'is_approved' => true,
            'max_concurrency' => 1,
            'current_load' => 0,
            'is_draining' => true,
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-drain',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $drainToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-urls',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $token = $this->createApprovedWorkerWithToken('worker-heartbeat');

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-heartbeat',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $leaseToken = $poll->json('data.job.lease_token');
        $dispatchId = $poll->json('data.job.dispatch_id');

        $before = AiJobDispatch::query()->find($dispatchId);

        $this->postJson('/api/worker/heartbeat', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-heartbeat',
        ], [
            'Authorization' => 'Bearer ' . $token,
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

        $dispatch = $this->createDispatch($tenant->id, $job->id);

        $this->postJson('/api/worker/heartbeat', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'invalid',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(404);

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'invalid',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(404);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatch->id,
            'lease_token' => 'invalid',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-fail',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail',
            'error_message' => 'boom',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(200);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail',
            'error_message' => 'boom',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-complete',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-complete',
            'provider_job_id' => 'prompt-1',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(200);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-complete',
            'error_message' => 'late-fail',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-fail-first',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail-first',
            'error_message' => 'fail-first',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(200);

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-fail-first',
            'provider_job_id' => 'prompt-late',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-output',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-no-output',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-no-output',
            'provider_job_id' => 'prompt-3',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(200);

        $job = $this->fetchTenantJob($tenant, $job->id);
        $this->assertSame('completed', $job->status);
    }

    public function test_orphan_dispatch_marked_failed(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $this->createEffect();

        $dispatch = $this->createDispatch($tenant->id, 999999);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-orphan',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenant->id, $lowJob->id, ['priority' => 0]);

        $this->createDispatch($tenant->id, $highJob->id, ['priority' => 5]);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-priority',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $poll->assertStatus(200)
            ->assertJsonPath('data.job.job_id', $highJob->id);
    }

    public function test_poll_filters_by_provider(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->createDispatch($tenant->id, $job->id, ['provider' => 'cloud']);

        $localPoll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-local',
            'providers' => ['self_hosted'],
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $localPoll->assertStatus(200)
            ->assertJsonPath('data.job', null);

        $cloudPoll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-cloud',
            'providers' => ['cloud'],
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $cloudPoll->assertStatus(200)
            ->assertJsonPath('data.job.job_id', $job->id)
            ->assertJsonPath('data.job.provider', 'cloud');
    }

    public function test_poll_skips_dispatch_after_max_attempts(): void
    {
        config(['services.comfyui.max_attempts' => 1]);

        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->createDispatch($tenant->id, $job->id, ['attempts' => 1]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-max-attempts',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
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

        $this->createDispatch($tenantA->id, $jobA->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'worker-isolation',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $dispatchId = $poll->json('data.job.dispatch_id');
        $leaseToken = $poll->json('data.job.lease_token');

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $dispatchId,
            'lease_token' => $leaseToken,
            'worker_id' => 'worker-isolation',
            'provider_job_id' => 'prompt-iso',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(200);

        $jobB = $this->fetchTenantJob($tenantB, $jobB->id);
        $this->assertSame('queued', $jobB->status);
    }

    // ========================================================================
    // New tests: per-worker auth, workflow filtering, audit logging, etc.
    // ========================================================================

    public function test_poll_with_per_worker_token_uses_authenticated_worker(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->createDispatch($tenant->id, $job->id);

        $perWorkerToken = 'per-worker-secret-token-xyz';
        $worker = ComfyUiWorker::query()->create([
            'worker_id' => 'known-worker',
            'display_name' => 'Known Worker',
            'token_hash' => hash('sha256', $perWorkerToken),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 2,
        ]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'different-id-in-body',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $perWorkerToken,
        ]);

        $response->assertStatus(200);
        $dispatchId = $response->json('data.job.dispatch_id');
        if ($dispatchId) {
            $dispatch = AiJobDispatch::query()->find($dispatchId);
            // Dispatch should be leased to the DB worker's worker_id, not the request body's
            $this->assertSame('known-worker', $dispatch->worker_id);
        }

        // Verify no new worker was created with 'different-id-in-body'
        $this->assertNull(ComfyUiWorker::query()->where('worker_id', 'different-id-in-body')->first());
    }

    public function test_poll_filters_dispatches_by_worker_assigned_workflows(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);

        $wfA = $this->createWorkflow(['name' => 'A', 'slug' => 'wf-a']);
        $wfB = $this->createWorkflow(['name' => 'B', 'slug' => 'wf-b']);

        $jobA = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $jobB = $this->createTenantJob($tenant, $user, $effect, $fileId);

        $this->createDispatch($tenant->id, $jobA->id, ['workflow_id' => $wfA->id]);
        $this->createDispatch($tenant->id, $jobB->id, ['workflow_id' => $wfB->id]);

        $perToken = 'wf-filter-token';
        $worker = ComfyUiWorker::query()->create([
            'worker_id' => 'wf-filter-worker',
            'token_hash' => hash('sha256', $perToken),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 2,
        ]);
        // Assign only to workflow A
        $worker->workflows()->sync([$wfA->id]);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'wf-filter-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $perToken,
        ]);

        $response->assertStatus(200);
        $jobId = $response->json('data.job.job_id');
        $this->assertSame($jobA->id, $jobId);
    }

    public function test_poll_without_workflow_assignment_gets_no_dispatches(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);

        $wfA = $this->createWorkflow(['name' => 'A', 'slug' => 'wf-all-a']);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->createDispatch($tenant->id, $job->id, ['workflow_id' => $wfA->id]);

        $perToken = 'no-wf-token';
        ComfyUiWorker::query()->create([
            'worker_id' => 'no-wf-worker',
            'token_hash' => hash('sha256', $perToken),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 2,
        ]);
        // No workflow assignments — worker should get zero dispatches

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'no-wf-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $perToken,
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.job'));
    }

    public function test_poll_logs_audit_event(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->createDispatch($tenant->id, $job->id);

        $token = $this->createApprovedWorkerWithToken('audit-poll-worker');

        $this->postJson('/api/worker/poll', [
            'worker_id' => 'audit-poll-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $log = WorkerAuditLog::query()
            ->where('event', 'poll')
            ->where('worker_identifier', 'audit-poll-worker')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_complete_logs_audit_event(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->seedWallet($tenant->id, $user->id, 25);
        $this->reserveTokens($tenant, $job, 5);
        $this->createDispatch($tenant->id, $job->id);

        $token = $this->createApprovedWorkerWithToken('audit-complete-worker');

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'audit-complete-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $this->postJson('/api/worker/complete', [
            'dispatch_id' => $poll->json('data.job.dispatch_id'),
            'lease_token' => $poll->json('data.job.lease_token'),
            'worker_id' => 'audit-complete-worker',
            'provider_job_id' => 'prompt-audit',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $log = WorkerAuditLog::query()
            ->where('event', 'complete')
            ->where('worker_identifier', 'audit-complete-worker')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_fail_logs_audit_event_with_error_metadata(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->seedWallet($tenant->id, $user->id, 25);
        $this->reserveTokens($tenant, $job, 5);
        $this->createDispatch($tenant->id, $job->id);

        $token = $this->createApprovedWorkerWithToken('audit-fail-worker');

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'audit-fail-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $poll->json('data.job.dispatch_id'),
            'lease_token' => $poll->json('data.job.lease_token'),
            'worker_id' => 'audit-fail-worker',
            'error_message' => 'GPU out of memory',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $log = WorkerAuditLog::query()
            ->where('event', 'fail')
            ->where('worker_identifier', 'audit-fail-worker')
            ->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('error', $log->metadata);
        $this->assertSame('GPU out of memory', $log->metadata['error']);
    }

    public function test_complete_presigns_asset_download_urls(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);

        // Set input_payload with assets
        $this->setJobInputPayload($tenant->id, $job->id, [
            'output_extension' => 'mp4',
            'assets' => [
                [
                    'key' => 'bg',
                    's3_path' => 'workflows/1/assets/bg.png',
                    's3_disk' => 's3',
                    'placeholder' => '{{BG}}',
                    'type' => 'image',
                ],
            ],
        ]);

        $this->createDispatch($tenant->id, $job->id);

        $response = $this->postJson('/api/worker/poll', [
            'worker_id' => 'asset-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $response->assertStatus(200);
        $assets = $response->json('data.job.input_payload.assets');
        $this->assertNotEmpty($assets);
        $this->assertArrayHasKey('download_url', $assets[0]);
        $this->assertNotNull($assets[0]['download_url']);
    }

    public function test_complete_runs_output_validation_non_blocking(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->seedWallet($tenant->id, $user->id, 25);
        $this->reserveTokens($tenant, $job, 5);
        $this->createDispatch($tenant->id, $job->id);

        // Mock OutputValidationService to throw
        app()->instance(OutputValidationService::class, new class extends OutputValidationService {
            public function validate(string $disk, string $path): array
            {
                throw new \RuntimeException('Validation service crashed');
            }
        });

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'validation-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $response = $this->postJson('/api/worker/complete', [
            'dispatch_id' => $poll->json('data.job.dispatch_id'),
            'lease_token' => $poll->json('data.job.lease_token'),
            'worker_id' => 'validation-worker',
            'provider_job_id' => 'prompt-val',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        // Should still return 200 despite validation crash
        $response->assertStatus(200);
    }

    public function test_fail_sanitizes_json_error_message(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->seedWallet($tenant->id, $user->id, 25);
        $this->reserveTokens($tenant, $job, 5);
        $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'sanitize-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $poll->json('data.job.dispatch_id'),
            'lease_token' => $poll->json('data.job.lease_token'),
            'worker_id' => 'sanitize-worker',
            'error_message' => json_encode(['exception_message' => 'Out of memory']),
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(200);

        $dispatch = AiJobDispatch::query()->find($poll->json('data.job.dispatch_id'));
        $this->assertSame('Out of memory', $dispatch->last_error);
    }

    public function test_fail_sanitizes_empty_error_message(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->seedWallet($tenant->id, $user->id, 25);
        $this->reserveTokens($tenant, $job, 5);
        $this->createDispatch($tenant->id, $job->id);

        $poll = $this->postJson('/api/worker/poll', [
            'worker_id' => 'empty-err-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ]);

        $this->postJson('/api/worker/fail', [
            'dispatch_id' => $poll->json('data.job.dispatch_id'),
            'lease_token' => $poll->json('data.job.lease_token'),
            'worker_id' => 'empty-err-worker',
            'error_message' => '',
        ], [
            'Authorization' => 'Bearer ' . $this->defaultToken,
        ])->assertStatus(200);

        $dispatch = AiJobDispatch::query()->find($poll->json('data.job.dispatch_id'));
        $this->assertSame('Processing failed.', $dispatch->last_error);
    }

    public function test_poll_updates_worker_last_ip(): void
    {
        [$user, $tenant] = $this->createUserTenant();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $job = $this->createTenantJob($tenant, $user, $effect, $fileId);
        $this->createDispatch($tenant->id, $job->id);

        // Per-worker auth sets last_ip via updateWorkerFromRequest
        $perToken = 'ip-test-token';
        $worker = ComfyUiWorker::query()->create([
            'worker_id' => 'ip-worker',
            'token_hash' => hash('sha256', $perToken),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 2,
        ]);

        $this->assertNull($worker->last_ip);

        $this->postJson('/api/worker/poll', [
            'worker_id' => 'ip-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ], [
            'Authorization' => 'Bearer ' . $perToken,
        ])->assertStatus(200);

        $worker->refresh();
        $this->assertNotNull($worker->last_ip);
    }

    // ========================================================================
    // Helper methods
    // ========================================================================

    private function createApprovedWorkerWithToken(string $workerId): string
    {
        $token = 'test-token-' . $workerId . '-' . uniqid();
        $worker = ComfyUiWorker::query()->create([
            'worker_id' => $workerId,
            'token_hash' => hash('sha256', $token),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 5,
        ]);
        $worker->workflows()->sync([$this->defaultWorkflow->id]);
        return $token;
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

    private function createEffect(?int $workflowId = null): Effect
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
            'workflow_id' => $workflowId ?? $this->defaultWorkflow->id,
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

    private function createDispatch(string $tenantId, int $jobId, array $overrides = []): AiJobDispatch
    {
        $defaults = [
            'tenant_id' => $tenantId,
            'tenant_job_id' => $jobId,
            'provider' => config('services.comfyui.default_provider', 'self_hosted'),
            'status' => 'queued',
            'priority' => 0,
            'attempts' => 0,
            'workflow_id' => $this->defaultWorkflow->id,
        ];

        return AiJobDispatch::query()->create(array_merge($defaults, $overrides));
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

    private function setJobInputPayload(string $tenantId, int $jobId, array $payload): void
    {
        DB::connection('tenant_pool_1')->table('ai_jobs')
            ->where('id', $jobId)
            ->update(['input_payload' => json_encode($payload)]);
    }
}
