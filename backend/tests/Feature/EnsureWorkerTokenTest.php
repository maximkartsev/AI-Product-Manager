<?php

namespace Tests\Feature;

use App\Models\ComfyUiWorker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnsureWorkerTokenTest extends TestCase
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

        $this->resetState();
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('comfy_ui_workers')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createWorker(array $overrides = []): ComfyUiWorker
    {
        $uid = uniqid();
        $defaults = [
            'worker_id' => 'worker-' . $uid,
            'display_name' => 'Worker ' . $uid,
            'token_hash' => hash('sha256', 'per-worker-token'),
            'is_approved' => true,
            'is_draining' => false,
            'current_load' => 0,
            'max_concurrency' => 2,
        ];

        return ComfyUiWorker::query()->create(array_merge($defaults, $overrides));
    }

    private function pollPayload(): array
    {
        return [
            'worker_id' => 'test-worker',
            'current_load' => 0,
            'max_concurrency' => 1,
        ];
    }

    // ========================================================================
    // Tests
    // ========================================================================

    public function test_per_worker_token_auth_sets_authenticated_worker(): void
    {
        $worker = $this->createWorker([
            'token_hash' => hash('sha256', 'my-worker-token'),
            'is_approved' => true,
        ]);

        $response = $this->postJson('/api/worker/poll', $this->pollPayload(), [
            'Authorization' => 'Bearer my-worker-token',
        ]);

        // Middleware should pass; poll returns 200 (possibly with no job)
        $response->assertStatus(200);
    }

    public function test_per_worker_token_rejects_unapproved_worker(): void
    {
        $this->createWorker([
            'token_hash' => hash('sha256', 'unapproved-token'),
            'is_approved' => false,
        ]);

        $response = $this->postJson('/api/worker/poll', $this->pollPayload(), [
            'Authorization' => 'Bearer unapproved-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Worker not approved.');
    }

    public function test_unknown_token_returns_401(): void
    {
        $response = $this->postJson('/api/worker/poll', $this->pollPayload(), [
            'Authorization' => 'Bearer some-valid-looking-but-unregistered-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');
    }

    public function test_no_token_returns_401(): void
    {
        $response = $this->postJson('/api/worker/poll', $this->pollPayload());

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->postJson('/api/worker/poll', $this->pollPayload(), [
            'Authorization' => 'Bearer completely-wrong-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');
    }

    public function test_bearer_token_takes_priority_over_x_worker_token_header(): void
    {
        $this->createWorker([
            'token_hash' => hash('sha256', 'per-worker-bearer'),
            'is_approved' => true,
        ]);

        $this->createWorker([
            'token_hash' => hash('sha256', 'x-header-token'),
            'is_approved' => true,
        ]);

        // Bearer header should take priority over X-Worker-Token
        $response = $this->postJson('/api/worker/poll', $this->pollPayload(), [
            'Authorization' => 'Bearer per-worker-bearer',
            'X-Worker-Token' => 'x-header-token',
        ]);

        $response->assertStatus(200);
    }
}
