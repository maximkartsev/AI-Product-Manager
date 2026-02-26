<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\Workflow;
use App\Services\PresignedUrlService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EffectsApiTest extends TestCase
{
    protected static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$prepared) {
            Artisan::call('migrate');
            static::$prepared = true;
        }

        config(['services.comfyui.presigned_ttl_seconds' => 900]);
        config(['filesystems.default' => 's3']);
        Storage::fake('s3');
        app()->instance(PresignedUrlService::class, new class extends PresignedUrlService {
            public function downloadUrl(string $disk, string $path, int $ttlSeconds): string
            {
                return "signed://{$path}";
            }
        });
    }

    public function test_effect_publication_status_defaults_to_published(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
        ]);

        $effect = Effect::query()->create([
            'name' => 'Default Publication Effect ' . uniqid(),
            'slug' => 'default-pub-' . uniqid(),
            'description' => 'Default publication status',
            'is_premium' => false,
            'is_active' => true,
            'workflow_id' => $workflow->id,
        ]);

        $effect->refresh();

        $this->assertSame('published', $effect->publication_status);
    }

    public function test_effect_can_set_publication_status(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
        ]);

        $effect = Effect::query()->create([
            'name' => 'Dev Publication Effect ' . uniqid(),
            'slug' => 'dev-pub-' . uniqid(),
            'description' => 'Dev publication status',
            'is_premium' => false,
            'is_active' => true,
            'workflow_id' => $workflow->id,
            'publication_status' => 'development',
        ]);

        $effect->refresh();

        $this->assertSame('development', $effect->publication_status);
    }

    public function test_effects_index_returns_only_published_active_effects(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
        ]);

        $active = Effect::query()->create([
            'name' => 'Active Effect ' . uniqid(),
            'slug' => 'active-' . uniqid(),
            'description' => 'Active effect description',
            'thumbnail_url' => 'https://example.com/thumb.png',
            'preview_video_url' => 'https://example.com/preview.mp4',
            'is_premium' => false,
            'is_active' => true,
            'publication_status' => 'published',
            'workflow_id' => $workflow->id,
        ]);

        $development = Effect::query()->create([
            'name' => 'Dev Effect ' . uniqid(),
            'slug' => 'dev-' . uniqid(),
            'description' => 'Development effect description',
            'is_premium' => false,
            'is_active' => true,
            'publication_status' => 'development',
            'workflow_id' => $workflow->id,
        ]);

        $inactive = Effect::query()->create([
            'name' => 'Inactive Effect ' . uniqid(),
            'slug' => 'inactive-' . uniqid(),
            'description' => 'Inactive effect description',
            'is_premium' => false,
            'is_active' => false,
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->getJson('/api/effects');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $response->assertJsonFragment(['slug' => $active->slug]);
        $response->assertJsonMissing(['slug' => $development->slug]);
        $response->assertJsonMissing(['slug' => $inactive->slug]);
    }

    public function test_effect_show_by_slug_returns_effect(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
        ]);

        $effect = Effect::query()->create([
            'name' => 'Show Effect ' . uniqid(),
            'slug' => 'show-' . uniqid(),
            'description' => 'Show effect description',
            'is_premium' => true,
            'is_active' => true,
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->getJson('/api/effects/' . $effect->slug);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonPath('data.slug', $effect->slug);
        $response->assertJsonPath('data.is_premium', true);
    }

    public function test_effect_show_rejects_development_effect(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
        ]);

        $effect = Effect::query()->create([
            'name' => 'Dev Effect ' . uniqid(),
            'slug' => 'dev-show-' . uniqid(),
            'description' => 'Dev effect description',
            'is_premium' => true,
            'is_active' => true,
            'publication_status' => 'development',
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->getJson('/api/effects/' . $effect->slug);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_effect_show_includes_configurable_properties(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
            'properties' => [
                [
                    'key' => 'positive_prompt',
                    'name' => 'Style Prompt',
                    'type' => 'text',
                    'placeholder' => '__POSITIVE_PROMPT__',
                    'user_configurable' => true,
                    'is_primary_input' => false,
                ],
            ],
        ]);

        $effect = Effect::query()->create([
            'name' => 'Show Effect ' . uniqid(),
            'slug' => 'show-' . uniqid(),
            'description' => 'Show effect description',
            'is_premium' => true,
            'is_active' => true,
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->getJson('/api/effects/' . $effect->slug);

        $response->assertStatus(200);
        $response->assertJsonPath('data.configurable_properties.0.key', 'positive_prompt');
    }

    public function test_effect_show_uses_effect_override_default_value(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
            'properties' => [
                [
                    'key' => 'positive_prompt',
                    'name' => 'Style Prompt',
                    'type' => 'text',
                    'placeholder' => '__POSITIVE_PROMPT__',
                    'user_configurable' => true,
                    'is_primary_input' => false,
                    'default_value' => 'workflow default',
                ],
            ],
        ]);

        $effect = Effect::query()->create([
            'name' => 'Override Effect ' . uniqid(),
            'slug' => 'override-' . uniqid(),
            'description' => 'Override effect description',
            'is_premium' => false,
            'is_active' => true,
            'workflow_id' => $workflow->id,
            'property_overrides' => [
                'positive_prompt' => 'override default',
            ],
        ]);

        $response = $this->getJson('/api/effects/' . $effect->slug);

        $response->assertStatus(200);
        $response->assertJsonPath('data.configurable_properties.0.default_value', 'override default');
    }

    public function test_effect_show_presign_ignores_query_params(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workflow ' . uniqid(),
            'slug' => 'workflow-' . uniqid(),
            'is_active' => true,
        ]);

        $effect = Effect::query()->create([
            'name' => 'Preview Effect ' . uniqid(),
            'slug' => 'preview-' . uniqid(),
            'description' => 'Preview effect description',
            'is_premium' => false,
            'is_active' => true,
            'workflow_id' => $workflow->id,
            'thumbnail_url' => 'https://minio.example/bp-media/effects/thumbnails/foo.jpg?X-Amz-Signature=abc',
        ]);

        $response = $this->getJson('/api/effects/' . $effect->slug);

        $response->assertStatus(200);
        $url = $response->json('data.thumbnail_url');
        $this->assertStringContainsString('effects/thumbnails/foo.jpg', (string) $url);
        $this->assertStringNotContainsString('X-Amz-', (string) $url);
    }

    public function test_effect_show_missing_returns_404_envelope(): void
    {
        $response = $this->getJson('/api/effects/does-not-exist-' . uniqid());

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }
}

