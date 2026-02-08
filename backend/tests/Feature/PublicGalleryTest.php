<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\GalleryVideo;
use App\Services\PresignedUrlService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicGalleryTest extends TestCase
{
    protected static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$prepared) {
            Artisan::call('migrate');
            static::$prepared = true;
        }

        config(['filesystems.default' => 's3']);
        config(['services.comfyui.presigned_ttl_seconds' => 900]);

        Storage::fake('s3');

        app()->instance(PresignedUrlService::class, new class extends PresignedUrlService {
            public function downloadUrl(string $disk, string $path, int $ttlSeconds): string
            {
                $normalizedPath = ltrim($path, '/');
                return "https://example.com/presigned/{$normalizedPath}";
            }
        });
    }

    public function test_gallery_index_returns_public_items_with_presigned_urls(): void
    {
        $effect = Effect::query()->create([
            'name' => 'Gallery Effect ' . uniqid(),
            'slug' => 'gallery-' . uniqid(),
            'description' => 'Gallery effect',
            'is_premium' => false,
            'is_active' => true,
        ]);

        $public = GalleryVideo::query()->create([
            'tenant_id' => 'tenant-demo',
            'user_id' => 1,
            'video_id' => 101,
            'effect_id' => $effect->id,
            'tags' => ['style', 'neon'],
            'is_public' => true,
            'processed_file_url' => Storage::disk('s3')->url('tenants/tenant-demo/processed.mp4'),
            'thumbnail_url' => Storage::disk('s3')->url('tenants/tenant-demo/thumbnail.jpg'),
        ]);

        $private = GalleryVideo::query()->create([
            'tenant_id' => 'tenant-demo',
            'user_id' => 1,
            'video_id' => 102,
            'effect_id' => $effect->id,
            'tags' => ['style'],
            'is_public' => false,
            'processed_file_url' => Storage::disk('s3')->url('tenants/tenant-demo/private.mp4'),
        ]);

        $response = $this->getJson('/api/gallery?tags:like=style');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonFragment(['id' => $public->id]);
        $response->assertJsonMissing(['id' => $private->id]);
        $response->assertJsonPath('data.items.0.effect.slug', $effect->slug);
        $response->assertJsonPath(
            'data.items.0.processed_file_url',
            'https://example.com/presigned/tenants/tenant-demo/processed.mp4'
        );
        $response->assertJsonPath(
            'data.items.0.thumbnail_url',
            'https://example.com/presigned/tenants/tenant-demo/thumbnail.jpg'
        );
    }

    public function test_gallery_show_requires_public_item(): void
    {
        $effect = Effect::query()->create([
            'name' => 'Gallery Effect ' . uniqid(),
            'slug' => 'gallery-' . uniqid(),
            'description' => 'Gallery effect',
            'is_premium' => false,
            'is_active' => true,
        ]);

        $public = GalleryVideo::query()->create([
            'tenant_id' => 'tenant-demo',
            'user_id' => 1,
            'video_id' => 201,
            'effect_id' => $effect->id,
            'tags' => ['style'],
            'is_public' => true,
            'processed_file_url' => Storage::disk('s3')->url('tenants/tenant-demo/public-2.mp4'),
        ]);

        $private = GalleryVideo::query()->create([
            'tenant_id' => 'tenant-demo',
            'user_id' => 1,
            'video_id' => 202,
            'effect_id' => $effect->id,
            'tags' => ['style'],
            'is_public' => false,
            'processed_file_url' => Storage::disk('s3')->url('tenants/tenant-demo/private-2.mp4'),
        ]);

        $this->getJson('/api/gallery/' . $public->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $public->id);

        $this->getJson('/api/gallery/' . $private->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
