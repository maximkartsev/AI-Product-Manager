<?php

namespace Tests\Feature;

use App\Models\ComfyUiAssetBundle;
use App\Models\ComfyUiAssetBundleFile;
use App\Models\ComfyUiAssetFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PresignedUrlService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminComfyUiAssetsTest extends TestCase
{
    protected static bool $prepared = false;

    private User $adminUser;
    private Tenant $tenant;
    private $presignedStub;

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

        Storage::fake('comfyui_models');
        config(['services.comfyui.models_disk' => 'comfyui_models']);

        $this->presignedStub = new class extends PresignedUrlService {
            public array $multipartCreated = [];
            public array $multipartCompleted = [];
            public array $multipartAborted = [];

            public function downloadUrl(string $disk, string $path, int $ttlSeconds): string
            {
                return 'https://example.com/download';
            }

            public function uploadUrl(string $disk, string $path, int $ttlSeconds, ?string $contentType = null): array
            {
                return ['url' => 'https://example.com/upload', 'headers' => ['Content-Type' => $contentType]];
            }

            public function createMultipartUpload(string $disk, string $key, ?string $contentType = null): string
            {
                $this->multipartCreated = [
                    'disk' => $disk,
                    'key' => $key,
                    'content_type' => $contentType,
                ];
                return 'upload-123';
            }

            public function createMultipartUploadPartUrls(string $disk, string $key, string $uploadId, int $partCount, int $ttlSeconds): array
            {
                $urls = [];
                for ($i = 1; $i <= $partCount; $i++) {
                    $urls[] = ['part_number' => $i, 'url' => "https://example.com/upload/part-{$i}"];
                }
                return $urls;
            }

            public function completeMultipartUpload(string $disk, string $key, string $uploadId, array $parts): void
            {
                $this->multipartCompleted = [
                    'disk' => $disk,
                    'key' => $key,
                    'upload_id' => $uploadId,
                    'parts' => $parts,
                ];
            }

            public function abortMultipartUpload(string $disk, string $key, string $uploadId): void
            {
                $this->multipartAborted = [
                    'disk' => $disk,
                    'key' => $key,
                    'upload_id' => $uploadId,
                ];
            }
        };
        app()->instance(PresignedUrlService::class, $this->presignedStub);
    }

    private function resetState(): void
    {
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('central')->table('users')->truncate();
        DB::connection('central')->table('tenants')->truncate();
        DB::connection('central')->table('personal_access_tokens')->truncate();
        DB::connection('central')->table('comfyui_asset_bundle_files')->truncate();
        DB::connection('central')->table('comfyui_asset_bundles')->truncate();
        DB::connection('central')->table('comfyui_asset_files')->truncate();
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

    private function adminPost(string $uri, array $data = [])
    {
        $this->actAsAdmin();
        return $this->postJson($uri, $data);
    }

    private function adminGet(string $uri, array $query = [])
    {
        $this->actAsAdmin();
        $url = $uri . ($query ? '?' . http_build_query($query) : '');
        return $this->getJson($url);
    }

    public function test_assets_upload_init_returns_presigned_url(): void
    {
        $sha256 = str_repeat('a', 64);
        $response = $this->adminPost('/api/admin/comfyui-assets/uploads', [
            'kind' => 'checkpoint',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 1024,
            'original_filename' => 'model.safetensors',
            'sha256' => $sha256,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame("assets/checkpoint/{$sha256}", $response->json('data.path'));
        $this->assertSame('https://example.com/upload', $response->json('data.upload_url'));
        $this->assertFalse((bool) $response->json('data.already_exists'));
    }

    public function test_assets_multipart_upload_init_returns_part_urls(): void
    {
        $sha256 = str_repeat('e', 64);
        $sizeBytes = 200 * 1024 * 1024;
        $response = $this->adminPost('/api/admin/comfyui-assets/uploads/multipart', [
            'kind' => 'checkpoint',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => $sizeBytes,
            'original_filename' => 'big-model.safetensors',
            'sha256' => $sha256,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.upload_id', 'upload-123')
            ->assertJsonPath('data.key', "assets/checkpoint/{$sha256}");

        $partUrls = $response->json('data.part_urls');
        $this->assertIsArray($partUrls);
        $this->assertCount(2, $partUrls);
        $this->assertSame(1, $partUrls[0]['part_number']);
        $this->assertSame('https://example.com/upload/part-1', $partUrls[0]['url']);
    }

    public function test_assets_multipart_upload_complete_records_parts(): void
    {
        $payload = [
            'upload_id' => 'upload-123',
            'key' => 'assets/checkpoint/abc123',
            'parts' => [
                ['part_number' => 1, 'etag' => 'etag-1'],
                ['part_number' => 2, 'etag' => 'etag-2'],
            ],
        ];

        $response = $this->adminPost('/api/admin/comfyui-assets/uploads/multipart/complete', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame('assets/checkpoint/abc123', $this->presignedStub->multipartCompleted['key'] ?? null);
        $this->assertSame('upload-123', $this->presignedStub->multipartCompleted['upload_id'] ?? null);
        $this->assertCount(2, $this->presignedStub->multipartCompleted['parts'] ?? []);
    }

    public function test_assets_upload_init_accepts_text_encoder_kind(): void
    {
        $sha256 = str_repeat('c', 64);
        $response = $this->adminPost('/api/admin/comfyui-assets/uploads', [
            'kind' => 'text_encoder',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 1024,
            'original_filename' => 'text-encoder.safetensors',
            'sha256' => $sha256,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame("assets/text_encoder/{$sha256}", $response->json('data.path'));
    }

    public function test_assets_upload_init_accepts_diffusion_model_kind(): void
    {
        $sha256 = str_repeat('d', 64);
        $response = $this->adminPost('/api/admin/comfyui-assets/uploads', [
            'kind' => 'diffusion_model',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 1024,
            'original_filename' => 'diffusion-model.safetensors',
            'sha256' => $sha256,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame("assets/diffusion_model/{$sha256}", $response->json('data.path'));
    }

    public function test_assets_files_store_creates_asset_record(): void
    {
        $sha256 = str_repeat('b', 64);
        $response = $this->adminPost('/api/admin/comfyui-assets/files', [
            'kind' => 'lora',
            'original_filename' => 'style-lora.safetensors',
            'content_type' => 'application/octet-stream',
            'size_bytes' => 2048,
            'sha256' => $sha256,
            'notes' => 'test asset',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $asset = ComfyUiAssetFile::query()->first();
        $this->assertNotNull($asset);
        $this->assertSame('lora', $asset->kind);
        $this->assertSame($sha256, $asset->sha256);
        $this->assertSame("assets/lora/{$sha256}", $asset->s3_key);
    }

    public function test_assets_delete_is_blocked_when_used_in_bundle(): void
    {
        $asset = ComfyUiAssetFile::query()->create([
            'kind' => 'checkpoint',
            'original_filename' => 'sdxl.safetensors',
            's3_key' => 'assets/checkpoint/abc123',
            'content_type' => 'application/octet-stream',
            'size_bytes' => 1234,
            'sha256' => 'abc123',
        ]);

        $bundle = ComfyUiAssetBundle::query()->create([
            'bundle_id' => (string) Str::uuid(),
            'name' => 'Used Bundle',
            's3_prefix' => 'bundles/used-bundle',
        ]);

        ComfyUiAssetBundleFile::query()->create([
            'bundle_id' => $bundle->id,
            'asset_file_id' => $asset->id,
            'target_path' => 'models/checkpoints/custom.safetensors',
            'position' => 0,
        ]);

        $this->actAsAdmin();
        $response = $this->deleteJson("/api/admin/comfyui-assets/files/{$asset->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.bundles.0.id', $bundle->id)
            ->assertJsonPath('data.bundles.0.bundle_id', $bundle->bundle_id)
            ->assertJsonPath('data.bundles.0.name', $bundle->name);
    }

    public function test_bundles_store_writes_manifest_and_bundle_files(): void
    {
        $asset = ComfyUiAssetFile::query()->create([
            'kind' => 'checkpoint',
            'original_filename' => 'sdxl.safetensors',
            's3_key' => 'assets/checkpoint/abc123',
            'content_type' => 'application/octet-stream',
            'size_bytes' => 1234,
            'sha256' => 'abc123',
        ]);

        $response = $this->adminPost('/api/admin/comfyui-assets/bundles', [
            'name' => 'Base Models',
            'asset_file_ids' => [$asset->id],
            'asset_overrides' => [
                [
                    'asset_file_id' => $asset->id,
                    'target_path' => 'models/checkpoints/custom.safetensors',
                    'action' => 'copy',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $bundle = ComfyUiAssetBundle::query()->first();
        $this->assertNotNull($bundle);
        $this->assertSame(1, ComfyUiAssetBundleFile::query()->count());

        $manifestPath = $bundle->s3_prefix . '/manifest.json';
        Storage::disk('comfyui_models')->assertExists($manifestPath);
        $manifest = json_decode(Storage::disk('comfyui_models')->get($manifestPath), true);

        $this->assertSame($bundle->bundle_id, $manifest['bundle_id']);
        $this->assertCount(1, $manifest['assets']);
        $this->assertSame('models/checkpoints/custom.safetensors', $manifest['assets'][0]['target_path']);
    }

    public function test_bundle_manifest_returns_presigned_download_url(): void
    {
        $bundle = ComfyUiAssetBundle::query()->create([
            'bundle_id' => (string) Str::uuid(),
            'name' => 'Bundle',
            's3_prefix' => 'bundles/test-bundle',
        ]);

        $response = $this->adminGet("/api/admin/comfyui-assets/bundles/{$bundle->id}/manifest");
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.download_url', 'https://example.com/download');
    }
}
