<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\AiJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Tenancy;
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

        config(['services.comfyui.workflow_disk' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put(
            'resources/comfyui/workflows/cloud_video_effect.json',
            json_encode(['1' => ['inputs' => []]])
        );

        $this->resetState();
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_ai_job_submission_reserves_tokens_and_is_idempotent(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect(10.0);
        $this->seedWallet($tenant->id, $user->id, 25);
        $fileId = $this->createTenantFile($tenant->id, $user->id);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
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

    public function test_ai_job_submission_builds_payload_from_effect_workflow(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect(5.0);
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $jobId = $response->json('data.id');
        $this->assertNotNull($jobId);

        $job = $this->fetchTenantJob($tenant->id, $jobId);
        $payloadRaw = $job['input_payload'] ?? null;
        $inputPayload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : $payloadRaw;
        $this->assertIsArray($inputPayload);
        $this->assertArrayHasKey('workflow', $inputPayload);
    }

    public function test_ai_job_submission_replaces_workflow_prompt_placeholders_from_input_payload(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect(5.0);
        $workflowPath = 'resources/comfyui/workflows/test_prompt_override.json';

        Storage::disk('s3')->put($workflowPath, json_encode([
            '1' => [
                'inputs' => [
                    'prompt' => '__POSITIVE_PROMPT__',
                    'negative_prompt' => '__NEGATIVE_PROMPT__',
                ],
                'class_type' => 'TestNode',
            ],
            '2' => [
                'inputs' => [
                    'text' => 'prefix __POSITIVE_PROMPT__ suffix',
                ],
                'class_type' => 'TextNode',
            ],
        ]));

        $effect->workflow->update([
            'comfyui_workflow_path' => $workflowPath,
            'properties' => [
                ['key' => 'positive_prompt', 'type' => 'text', 'placeholder' => '__POSITIVE_PROMPT__', 'user_configurable' => true, 'default_value' => ''],
                ['key' => 'negative_prompt', 'type' => 'text', 'placeholder' => '__NEGATIVE_PROMPT__', 'user_configurable' => true, 'default_value' => ''],
                ['key' => 'input_video', 'type' => 'video', 'placeholder' => '__INPUT_PATH__', 'is_primary_input' => true],
            ],
        ]);

        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
            'input_payload' => [
                'positive_prompt' => 'updated positive',
                'negative_prompt' => 'updated negative',
            ],
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $jobId = $response->json('data.id');
        $this->assertNotNull($jobId);

        $job = $this->fetchTenantJob($tenant->id, $jobId);
        $payloadRaw = $job['input_payload'] ?? null;
        $inputPayload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : $payloadRaw;
        $this->assertIsArray($inputPayload);

        $workflow = $inputPayload['workflow'] ?? null;
        $this->assertIsArray($workflow);

        $nodeOneInputs = $workflow['1']['inputs'] ?? null;
        $this->assertIsArray($nodeOneInputs);
        $this->assertSame('updated positive', $nodeOneInputs['prompt'] ?? null);
        $this->assertSame('updated negative', $nodeOneInputs['negative_prompt'] ?? null);

        $nodeTwoInputs = $workflow['2']['inputs'] ?? null;
        $this->assertIsArray($nodeTwoInputs);
        $this->assertSame('prefix updated positive suffix', $nodeTwoInputs['text'] ?? null);
    }

    public function test_ai_job_submission_fails_when_tokens_insufficient(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect(50.0);
        $this->seedWallet($tenant->id, $user->id, 10);
        $fileId = $this->createTenantFile($tenant->id, $user->id);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
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
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'comfyui_workflow_path' => 'resources/comfyui/workflows/cloud_video_effect.json',
            'output_node_id' => '3',
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'is_active' => true,
        ]);

        return Effect::query()->create([
            'name' => 'Effect ' . uniqid(),
            'slug' => 'effect-' . uniqid(),
            'description' => 'Effect description',
            'type' => 'video',
            'credits_cost' => $creditsCost,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
            'workflow_id' => $workflow->id,
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

    private function fetchTenantJob(string $tenantId, int $jobId): AiJob
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            return AiJob::query()->findOrFail($jobId);
        } finally {
            $tenancy->end();
        }
    }

    private function postJsonWithHost(string $domain, string $uri, array $payload)
    {
        return $this->postJson('http://' . $domain . $uri, $payload);
    }
}
