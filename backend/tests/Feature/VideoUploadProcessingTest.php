<?php

namespace Tests\Feature;

use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\GalleryVideo;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Services\PresignedUrlService;
use App\Services\VideoCleanupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoUploadProcessingTest extends TestCase
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

        config(['services.comfyui.upload_max_bytes' => 1024 * 1024]);
        config(['services.comfyui.presigned_ttl_seconds' => 900]);
        config(['services.comfyui.workflow_disk' => 's3']);
        config(['filesystems.default' => 's3']);

        Storage::fake('s3');
        Storage::disk('s3')->put(
            'resources/comfyui/workflows/cloud_video_effect.json',
            json_encode(['1' => ['inputs' => []]])
        );

        $this->resetState();

        app()->instance(PresignedUrlService::class, new class extends PresignedUrlService {
            public function downloadUrl(string $disk, string $path, int $ttlSeconds): string
            {
                if ($ttlSeconds <= 0) {
                    throw new \RuntimeException('TTL expired.');
                }
                $normalizedPath = ltrim($path, '/');
                return "https://example.com/presigned/{$normalizedPath}";
            }

            public function uploadUrl(string $disk, string $path, int $ttlSeconds, ?string $contentType = null): array
            {
                if ($ttlSeconds <= 0) {
                    throw new \RuntimeException('TTL expired.');
                }
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
        DB::connection('central')->table('ai_job_dispatches')->truncate();
        DB::connection('central')->table('gallery_videos')->truncate();
        DB::connection('central')->statement('SET FOREIGN_KEY_CHECKS=1');

        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=0');
        DB::connection('tenant_pool_1')->table('ai_jobs')->truncate();
        DB::connection('tenant_pool_1')->table('token_transactions')->truncate();
        DB::connection('tenant_pool_1')->table('token_wallets')->truncate();
        DB::connection('tenant_pool_1')->table('files')->truncate();
        DB::connection('tenant_pool_1')->table('videos')->truncate();
        DB::connection('tenant_pool_1')->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_upload_initiation_success(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $this->seedWallet($tenant->id, $user->id, 25);
        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'original_filename' => 'input.mp4',
        ];

        $response = $this->postJsonWithHost($domain, '/api/videos/uploads', $payload);
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.upload_url', 'https://example.com/upload')
            ->assertJsonPath('data.upload_headers.Content-Type', 'video/mp4');

        $fileId = $response->json('data.file.id');
        $this->assertNotNull($fileId);

        $file = $this->fetchTenantFile($tenant->id, $fileId);
        $this->assertSame('video/mp4', $file['mime_type']);
        $this->assertSame('input.mp4', $file['original_filename']);
    }

    public function test_upload_fails_when_tokens_insufficient(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, '/api/videos/uploads', [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'original_filename' => 'input.mp4',
        ]);

        $requiredTokens = (int) ceil((float) $effect->credits_cost);
        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.required_tokens', $requiredTokens);
    }

    public function test_upload_requires_auth(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();

        $response = $this->postJsonWithHost($domain, '/api/videos/uploads', [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'original_filename' => 'input.mp4',
        ]);

        $response->assertStatus(401);
    }

    public function test_upload_rejects_tenant_mismatch(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $otherUser = User::factory()->create();

        Sanctum::actingAs($otherUser);

        $response = $this->postJsonWithHost($domain, '/api/videos/uploads', [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'original_filename' => 'input.mp4',
        ]);

        $response->assertStatus(403);
    }

    public function test_upload_validates_metadata_and_limits(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $this->seedWallet($tenant->id, $user->id, 25);
        Sanctum::actingAs($user);

        $cases = [
            ['payload' => ['size' => 1024, 'original_filename' => 'input.mp4'], 'label' => 'missing mime'],
            ['payload' => ['mime_type' => 'video/mp4', 'original_filename' => 'input.mp4'], 'label' => 'missing size'],
            ['payload' => ['mime_type' => 'video/mp4', 'size' => 1024], 'label' => 'missing filename'],
            ['payload' => ['mime_type' => 'application/pdf', 'size' => 1024, 'original_filename' => 'input.pdf'], 'label' => 'bad mime'],
            ['payload' => ['mime_type' => 'video/mp4', 'size' => 0, 'original_filename' => 'input.mp4'], 'label' => 'zero size'],
            ['payload' => ['mime_type' => 'video/mp4', 'size' => 1024, 'original_filename' => '../evil.mp4'], 'label' => 'bad filename'],
        ];

        foreach ($cases as $case) {
            $payload = array_merge($case['payload'], ['effect_id' => $effect->id]);
            $response = $this->postJsonWithHost($domain, '/api/videos/uploads', $payload);
            $response->assertStatus(422);
        }

        $tooLarge = $this->postJsonWithHost($domain, '/api/videos/uploads', [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 1024 * 1024 + 1,
            'original_filename' => 'input.mp4',
        ]);

        $tooLarge->assertStatus(422);
    }

    public function test_upload_duplicate_hash_creates_separate_files(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $this->seedWallet($tenant->id, $user->id, 25);
        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 512,
            'original_filename' => 'input.mp4',
            'file_hash' => 'hash-123',
        ];

        $first = $this->postJsonWithHost($domain, '/api/videos/uploads', $payload);
        $second = $this->postJsonWithHost($domain, '/api/videos/uploads', $payload);

        $first->assertStatus(200);
        $second->assertStatus(200);

        $count = (int) DB::connection('tenant_pool_1')
            ->table('files')
            ->where('tenant_id', $tenant->id)
            ->where('file_hash', 'hash-123')
            ->count();

        $this->assertSame(2, $count);
    }

    public function test_upload_fails_when_disk_unsupported(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $this->seedWallet($tenant->id, $user->id, 25);
        Sanctum::actingAs($user);

        app()->instance(PresignedUrlService::class, new PresignedUrlService());
        config(['filesystems.default' => 'local']);

        $response = $this->postJsonWithHost($domain, '/api/videos/uploads', [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'original_filename' => 'input.mp4',
        ]);

        $response->assertStatus(500);
    }

    public function test_upload_rejects_expired_presigned_ttl(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $this->seedWallet($tenant->id, $user->id, 25);
        Sanctum::actingAs($user);

        config(['services.comfyui.presigned_ttl_seconds' => 0]);

        $response = $this->postJsonWithHost($domain, '/api/videos/uploads', [
            'effect_id' => $effect->id,
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'original_filename' => 'input.mp4',
        ]);

        $response->assertStatus(500);
    }

    public function test_create_video_success(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, '/api/videos', [
            'effect_id' => $effect->id,
            'original_file_id' => $fileId,
            'title' => 'My video',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.is_public', false);
    }

    public function test_get_video_success_returns_processed_url(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $originalFileId = $this->createTenantFile($tenant->id, $user->id);
        $processedFileId = $this->createTenantFile($tenant->id, $user->id, [
            'path' => 'outputs/processed.mp4',
            'url' => 'https://example.com/output.mp4',
        ]);

        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, $originalFileId, [
            'status' => 'completed',
            'processed_file_id' => $processedFileId,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('http://' . $domain . "/api/videos/{$videoId}");
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $videoId)
            ->assertJsonPath('data.processed_file_url', 'https://example.com/presigned/outputs/processed.mp4')
            ->assertJsonPath('data.error', null);
    }

    public function test_get_video_returns_error_when_failed(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $originalFileId = $this->createTenantFile($tenant->id, $user->id);

        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, $originalFileId, [
            'status' => 'failed',
            'processing_details' => json_encode(['error' => 'Worker failure']),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('http://' . $domain . "/api/videos/{$videoId}");
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $videoId)
            ->assertJsonPath('data.error', 'Worker failure');
    }

    public function test_get_video_rejects_foreign_user_id(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $otherUser = User::factory()->create();
        $originalFileId = $this->createTenantFile($tenant->id, $otherUser->id);

        $videoId = $this->createTenantVideo($tenant->id, $otherUser->id, $effect->id, $originalFileId);

        Sanctum::actingAs($user);

        $response = $this->getJson('http://' . $domain . "/api/videos/{$videoId}");
        $response->assertStatus(403);
    }

    public function test_get_video_not_found(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        Sanctum::actingAs($user);

        $response = $this->getJson('http://' . $domain . '/api/videos/999999');
        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_create_video_requires_effect_id(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $fileId = $this->createTenantFile($tenant->id, $user->id);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, '/api/videos', [
            'original_file_id' => $fileId,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_video_rejects_foreign_file(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $otherUser = User::factory()->create();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $otherUser->id);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, '/api/videos', [
            'effect_id' => $effect->id,
            'original_file_id' => $fileId,
        ]);

        $response->assertStatus(403);
    }

    public function test_create_video_rejects_soft_deleted_file(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id, ['deleted_at' => now()]);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, '/api/videos', [
            'effect_id' => $effect->id,
            'original_file_id' => $fileId,
        ]);

        $response->assertStatus(404);
    }

    public function test_create_video_rejects_expired_file(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id, [
            'metadata' => json_encode(['expires_at' => now()->subDay()->toIso8601String()]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, '/api/videos', [
            'effect_id' => $effect->id,
            'original_file_id' => $fileId,
        ]);

        $response->assertStatus(422);
    }

    public function test_ai_job_submission_creates_dispatch_and_priority(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
            'priority' => 3,
            'input_payload' => ['prompt' => 'hello'],
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(200);

        $jobId = $response->json('data.id');
        $dispatch = AiJobDispatch::query()
            ->where('tenant_id', $tenant->id)
            ->where('tenant_job_id', $jobId)
            ->first();

        $this->assertNotNull($dispatch);
        $this->assertSame(3, $dispatch->priority);
    }

    public function test_ai_job_submission_with_video_uses_original_file(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, $fileId);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'video_id' => $videoId,
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(200);

        $jobId = $response->json('data.id');
        $job = $this->fetchTenantJob($tenant->id, $jobId);
        $this->assertSame($fileId, $job['input_file_id']);
    }

    public function test_ai_job_submission_rejects_invalid_input_payload(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
            'input_payload' => 'invalid',
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(422);
    }

    public function test_ai_job_submission_requires_input_file_or_video(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(422);
    }

    public function test_ai_job_submission_rejects_file_not_owned(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $otherUser = User::factory()->create();
        $fileId = $this->createTenantFile($tenant->id, $otherUser->id);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(403);
    }

    public function test_ai_job_submission_rejects_soft_deleted_video(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, $fileId, [
            'deleted_at' => now(),
        ]);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'video_id' => $videoId,
        ]);

        $response->assertStatus(404);
    }

    public function test_ai_job_submission_accepts_large_payload(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $fileId = $this->createTenantFile($tenant->id, $user->id);
        $this->seedWallet($tenant->id, $user->id, 25);

        Sanctum::actingAs($user);

        $payload = [
            'effect_id' => $effect->id,
            'idempotency_key' => 'job_' . uniqid(),
            'input_file_id' => $fileId,
            'input_payload' => ['workflow' => array_fill(0, 200, ['node' => str_repeat('x', 50)])],
        ];

        $response = $this->postJsonWithHost($domain, '/api/ai-jobs', $payload);
        $response->assertStatus(200);
    }

    public function test_publish_creates_gallery_video(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $effect->update(['tags' => ['neon', 'portrait']]);
        $processedFileId = $this->createTenantFile($tenant->id, $user->id, [
            'url' => 'https://example.com/output.mp4',
        ]);

        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, null, [
            'status' => 'completed',
            'processed_file_id' => $processedFileId,
            'title' => 'Video title',
            'input_payload' => json_encode([
                'positive_prompt' => 'Neon look',
                'negative_prompt' => 'blurry',
            ]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, "/api/videos/{$videoId}/publish", []);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_public', true);

        $gallery = GalleryVideo::query()
            ->where('tenant_id', $tenant->id)
            ->where('video_id', $videoId)
            ->first();

        $this->assertNotNull($gallery);
        $this->assertSame('https://example.com/output.mp4', $gallery->processed_file_url);
        $this->assertSame(['neon', 'portrait'], $gallery->tags);
        $this->assertIsArray($gallery->input_payload);
        $this->assertSame('Neon look', $gallery->input_payload['positive_prompt'] ?? null);
        $this->assertSame('blurry', $gallery->input_payload['negative_prompt'] ?? null);
    }

    public function test_unpublish_sets_gallery_private(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();
        $processedFileId = $this->createTenantFile($tenant->id, $user->id, [
            'url' => 'https://example.com/output.mp4',
        ]);

        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, null, [
            'status' => 'completed',
            'processed_file_id' => $processedFileId,
        ]);

        GalleryVideo::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'video_id' => $videoId,
            'effect_id' => $effect->id,
            'is_public' => true,
            'processed_file_url' => 'https://example.com/output.mp4',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, "/api/videos/{$videoId}/unpublish", []);
        $response->assertStatus(200)
            ->assertJsonPath('data.is_public', false);

        $gallery = GalleryVideo::query()
            ->where('tenant_id', $tenant->id)
            ->where('video_id', $videoId)
            ->first();

        $this->assertSame(false, $gallery->is_public);
    }

    public function test_publish_requires_processed_file(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();

        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, null, [
            'status' => 'completed',
            'processed_file_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJsonWithHost($domain, "/api/videos/{$videoId}/publish", []);
        $response->assertStatus(422);
    }

    public function test_cleanup_expired_videos(): void
    {
        [$user, $tenant, $domain] = $this->createUserTenantDomain();
        $effect = $this->createEffect();

        Storage::fake('local');

        $processedFileId = $this->createTenantFile($tenant->id, $user->id, [
            'disk' => 'local',
            'path' => 'expired/output.mp4',
            'url' => 'http://localhost/storage/expired/output.mp4',
        ]);

        Storage::disk('local')->put('expired/output.mp4', 'data');

        $videoId = $this->createTenantVideo($tenant->id, $user->id, $effect->id, null, [
            'status' => 'completed',
            'processed_file_id' => $processedFileId,
            'expires_at' => now()->subDay(),
            'is_public' => true,
        ]);

        GalleryVideo::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'video_id' => $videoId,
            'effect_id' => $effect->id,
            'is_public' => true,
            'processed_file_url' => 'http://localhost/storage/expired/output.mp4',
        ]);

        $count = app(VideoCleanupService::class)->cleanupExpiredVideos();
        $this->assertSame(1, $count);

        $video = $this->fetchTenantVideo($tenant->id, $videoId);
        $this->assertSame('expired', $video['status']);
        $this->assertNull($video['processed_file_id']);
        $this->assertFalse((bool) $video['is_public']);

        $gallery = GalleryVideo::query()
            ->where('tenant_id', $tenant->id)
            ->where('video_id', $videoId)
            ->first();
        $this->assertSame(false, $gallery->is_public);

        Storage::disk('local')->assertMissing('expired/output.mp4');
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

    private function createEffect(): Effect
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
            'credits_cost' => 5,
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

    private function createTenantFile(string $tenantId, int $userId, array $overrides = []): int
    {
        $defaults = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'disk' => 'local',
            'path' => 'uploads/' . uniqid() . '.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1234,
            'original_filename' => 'input.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return (int) DB::connection('tenant_pool_1')->table('files')->insertGetId(array_merge($defaults, $overrides));
    }

    private function createTenantVideo(
        string $tenantId,
        int $userId,
        int $effectId,
        ?int $originalFileId,
        array $overrides = []
    ): int {
        $defaults = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'effect_id' => $effectId,
            'original_file_id' => $originalFileId,
            'status' => 'queued',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return (int) DB::connection('tenant_pool_1')->table('videos')->insertGetId(array_merge($defaults, $overrides));
    }

    private function fetchTenantFile(string $tenantId, int $fileId): array
    {
        return (array) DB::connection('tenant_pool_1')
            ->table('files')
            ->where('tenant_id', $tenantId)
            ->where('id', $fileId)
            ->first();
    }

    private function fetchTenantJob(string $tenantId, int $jobId): array
    {
        return (array) DB::connection('tenant_pool_1')
            ->table('ai_jobs')
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->first();
    }

    private function fetchTenantVideo(string $tenantId, int $videoId): array
    {
        return (array) DB::connection('tenant_pool_1')
            ->table('videos')
            ->where('tenant_id', $tenantId)
            ->where('id', $videoId)
            ->first();
    }

    private function postJsonWithHost(string $domain, string $uri, array $payload)
    {
        return $this->postJson('http://' . $domain . $uri, $payload);
    }
}
