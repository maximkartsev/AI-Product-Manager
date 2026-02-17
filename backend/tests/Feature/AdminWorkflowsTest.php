<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Services\PresignedUrlService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminWorkflowsTest extends TestCase
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

        app()->instance(PresignedUrlService::class, new class extends PresignedUrlService {
            public function downloadUrl(string $disk, string $path, int $ttlSeconds): string
            {
                return 'https://example.com/download';
            }

            public function uploadUrl(string $disk, string $path, int $ttlSeconds, ?string $contentType = null): array
            {
                return ['url' => 'https://example.com/upload', 'headers' => ['Content-Type' => $contentType]];
            }
        });
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->table('workflows')->truncate();
        DB::connection('central')->table('effects')->truncate();
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

    private function adminDelete(string $uri)
    {
        $this->actAsAdmin();
        return $this->deleteJson($uri);
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

    // ========================================================================
    // Tests
    // ========================================================================

    public function test_workflows_index_requires_admin(): void
    {
        // Unauthenticated
        $this->getJson('/api/admin/workflows')->assertStatus(401);

        // Non-admin
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $nonAdmin->id,
            'db_pool' => 'tenant_pool_1',
        ]);
        Sanctum::actingAs($nonAdmin);
        $this->getJson('/api/admin/workflows')->assertStatus(403);
    }

    public function test_workflows_index_returns_paginated_list(): void
    {
        $this->createWorkflow(['name' => 'WF1', 'slug' => 'wf1']);
        $this->createWorkflow(['name' => 'WF2', 'slug' => 'wf2']);
        $this->createWorkflow(['name' => 'WF3', 'slug' => 'wf3']);

        $response = $this->adminGet('/api/admin/workflows');
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('totalItems', $data);
        $this->assertArrayHasKey('totalPages', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('perPage', $data);
        $this->assertSame(3, $data['totalItems']);
    }

    public function test_workflows_index_search_filters_by_name_slug_description(): void
    {
        $this->createWorkflow(['name' => 'Video Effect', 'slug' => 'video-effect', 'description' => 'Applies a video filter']);
        $this->createWorkflow(['name' => 'Audio Mixer', 'slug' => 'audio-mixer', 'description' => 'Mixes audio']);

        // Search by name
        $response = $this->adminGet('/api/admin/workflows', ['search' => 'Video']);
        $this->assertSame(1, $response->json('data.totalItems'));

        // Search by slug
        $response2 = $this->adminGet('/api/admin/workflows', ['search' => 'audio-mixer']);
        $this->assertSame(1, $response2->json('data.totalItems'));

        // Search by description
        $response3 = $this->adminGet('/api/admin/workflows', ['search' => 'filter']);
        $this->assertSame(1, $response3->json('data.totalItems'));
    }

    public function test_workflows_index_ordering(): void
    {
        $this->createWorkflow(['name' => 'Zebra', 'slug' => 'zebra']);
        $this->createWorkflow(['name' => 'Alpha', 'slug' => 'alpha']);

        $ascResponse = $this->adminGet('/api/admin/workflows', ['order' => 'name:asc']);
        $names = collect($ascResponse->json('data.items'))->pluck('name')->toArray();
        $this->assertSame('Alpha', $names[0]);

        $descResponse = $this->adminGet('/api/admin/workflows', ['order' => 'id:desc']);
        $ids = collect($descResponse->json('data.items'))->pluck('id')->toArray();
        $this->assertTrue($ids[0] > $ids[1]);
    }

    public function test_workflows_show_returns_workflow(): void
    {
        $wf = $this->createWorkflow(['name' => 'My Workflow', 'slug' => 'my-wf']);

        $response = $this->adminGet("/api/admin/workflows/{$wf->id}");
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'My Workflow')
            ->assertJsonPath('data.slug', 'my-wf');
    }

    public function test_workflows_show_returns_404_for_missing(): void
    {
        $this->adminGet('/api/admin/workflows/99999')->assertStatus(404);
    }

    public function test_workflows_create_returns_empty_resource(): void
    {
        $response = $this->adminGet('/api/admin/workflows/create');
        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_workflows_store_creates_workflow(): void
    {
        $response = $this->adminPost('/api/admin/workflows', [
            'name' => 'New Workflow',
            'slug' => 'new-workflow',
            'description' => 'A new one',
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Workflow')
            ->assertJsonPath('data.slug', 'new-workflow');

        $this->assertSame(1, Workflow::query()->where('slug', 'new-workflow')->count());
    }

    public function test_workflows_store_validates_required_fields(): void
    {
        $response = $this->adminPost('/api/admin/workflows', [
            'description' => 'No name or slug',
        ]);

        $response->assertStatus(422);
    }

    public function test_workflows_store_rejects_duplicate_slug(): void
    {
        $this->createWorkflow(['name' => 'First', 'slug' => 'unique-slug']);

        $response = $this->adminPost('/api/admin/workflows', [
            'name' => 'Second',
            'slug' => 'unique-slug',
        ]);

        $response->assertStatus(422);
    }

    public function test_workflows_update_partial_fields(): void
    {
        $wf = $this->createWorkflow(['name' => 'Original', 'slug' => 'original', 'description' => 'Old desc']);

        $response = $this->adminPatch("/api/admin/workflows/{$wf->id}", [
            'name' => 'Updated',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated');

        $wf->refresh();
        $this->assertSame('Updated', $wf->name);
        $this->assertSame('original', $wf->slug);
    }

    public function test_workflows_update_allows_same_slug_for_own_id(): void
    {
        $wf = $this->createWorkflow(['name' => 'Test', 'slug' => 'test-slug']);

        $response = $this->adminPatch("/api/admin/workflows/{$wf->id}", [
            'name' => 'Updated Name',
            'slug' => 'test-slug',
        ]);

        $response->assertStatus(200);
    }

    public function test_workflows_update_returns_404_for_missing(): void
    {
        $this->adminPatch('/api/admin/workflows/99999', ['name' => 'x'])->assertStatus(404);
    }

    public function test_workflows_destroy_deletes_orphan_workflow(): void
    {
        $wf = $this->createWorkflow();

        $response = $this->adminDelete("/api/admin/workflows/{$wf->id}");
        $response->assertStatus(204);

        $this->assertNull(Workflow::query()->find($wf->id));
    }

    public function test_workflows_destroy_returns_409_when_effects_exist(): void
    {
        $wf = $this->createWorkflow(['name' => 'Used', 'slug' => 'used-wf']);

        Effect::query()->create([
            'name' => 'Test Effect',
            'slug' => 'test-effect',
            'type' => 'video',
            'workflow_id' => $wf->id,
            'credits_cost' => 5,
            'popularity_score' => 0,
            'is_active' => true,
            'is_premium' => false,
            'is_new' => false,
        ]);

        $response = $this->adminDelete("/api/admin/workflows/{$wf->id}");
        $response->assertStatus(409);

        $data = $response->json('data');
        $this->assertArrayHasKey('effects', $data);
        $this->assertCount(1, $data['effects']);
    }

    public function test_workflows_destroy_returns_404_for_missing(): void
    {
        $this->adminDelete('/api/admin/workflows/99999')->assertStatus(404);
    }

    public function test_workflows_upload_generates_presigned_url_for_workflow_json(): void
    {
        $wf = $this->createWorkflow();

        $response = $this->adminPost('/api/admin/workflows/uploads', [
            'kind' => 'workflow_json',
            'workflow_id' => $wf->id,
            'mime_type' => 'application/json',
            'size' => 1024,
            'original_filename' => 'workflow.json',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $path = $response->json('data.path');
        $this->assertStringContainsString("workflows/{$wf->id}/workflow.json", $path);
        $this->assertNotNull($response->json('data.upload_url'));
    }

    public function test_workflows_upload_generates_presigned_url_for_property_asset(): void
    {
        $wf = $this->createWorkflow();

        $response = $this->adminPost('/api/admin/workflows/uploads', [
            'kind' => 'property_asset',
            'workflow_id' => $wf->id,
            'property_key' => 'bg_image',
            'mime_type' => 'image/png',
            'size' => 2048,
            'original_filename' => 'background.png',
        ]);

        $response->assertStatus(200);

        $path = $response->json('data.path');
        $this->assertStringContainsString("workflows/{$wf->id}/assets/bg_image/", $path);
        $this->assertStringEndsWith('.png', $path);
    }

    public function test_workflows_upload_validates_required_fields(): void
    {
        $response = $this->adminPost('/api/admin/workflows/uploads', []);

        $response->assertStatus(422);

        $data = $response->json('data');
        $this->assertArrayHasKey('kind', $data);
        $this->assertArrayHasKey('mime_type', $data);
        $this->assertArrayHasKey('size', $data);
        $this->assertArrayHasKey('original_filename', $data);
    }

    public function test_workflows_upload_rejects_unsafe_filename(): void
    {
        $base = [
            'kind' => 'workflow_json',
            'mime_type' => 'application/json',
            'size' => 100,
        ];

        $this->adminPost('/api/admin/workflows/uploads', array_merge($base, [
            'original_filename' => '../etc/passwd',
        ]))->assertStatus(422);

        $this->adminPost('/api/admin/workflows/uploads', array_merge($base, [
            'original_filename' => 'path/to/file.json',
        ]))->assertStatus(422);

        $this->adminPost('/api/admin/workflows/uploads', array_merge($base, [
            'original_filename' => 'path\\to\\file.json',
        ]))->assertStatus(422);
    }
}
