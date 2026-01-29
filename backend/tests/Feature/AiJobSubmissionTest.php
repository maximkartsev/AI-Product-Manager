<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiJobSubmissionTest extends TestCase
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
    }

    public function test_ai_job_submission_reserves_tokens_and_is_idempotent(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect(10.0);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_payload' => ['prompt' => 'hello'],
        ];

        $first = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $first->assertStatus(200)
            ->assertJsonPath('success', true);

        $jobId = $first->json('data.id');
        $this->assertNotNull($jobId);

        $this->assertSame(15, $this->getWalletBalance($tenant->id));
        $this->assertSame(1, $this->getJobTransactionCount($tenant->id, $jobId, 'JOB_RESERVE'));

        $second = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $second->assertStatus(200)
            ->assertJsonPath('data.id', $jobId);

        $this->assertSame(15, $this->getWalletBalance($tenant->id));
        $this->assertSame(1, $this->getJobTransactionCount($tenant->id, $jobId, 'JOB_RESERVE'));
    }

    public function test_ai_job_submission_fails_when_tokens_insufficient(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect(50.0);
        $this->seedWallet($tenant->id, $user->id, 10);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(10, $this->getWalletBalance($tenant->id));
        $this->assertSame(0, $this->getTokenTransactionsCount($tenant->id));
    }

    private function createUserTenantDomain(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'db_pool' => 'tenant_pool_1',
        ]);
        $domain = 'tenant-' . uniqid() . '.test';
        $tenant->domains()->create(['domain' => $domain]);

        return [$user, $tenant, $domain];
    }

    private function createEffect(float $creditsCost): Effect
    {
        return Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => $creditsCost,
            'processing_time_estimate' => 10,
            'popularity_score' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
        ]);
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

    private function getWalletBalance(string $tenantId): int
    {
        return (int) DB::connection('tenant_pool_1')
            ->table('token_wallets')
            ->where('tenant_id', $tenantId)
            ->value('balance');
    }

    private function getTokenTransactionsCount(string $tenantId): int
    {
        return (int) DB::connection('tenant_pool_1')
            ->table('token_transactions')
            ->where('tenant_id', $tenantId)
            ->count();
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

    private function postJsonWithHost(string $domain, string $uri, array $payload)
    {
        return $this->withServerVariables([
            'HTTP_HOST' => $domain,
        ])->postJson($uri, $payload);
    }
}
