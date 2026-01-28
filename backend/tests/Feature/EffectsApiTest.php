<?php

namespace Tests\Feature;

use App\Models\Effect;
use Illuminate\Support\Facades\Artisan;
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
    }

    public function test_effects_index_returns_only_active_effects(): void
    {
        $active = Effect::query()->create([
            'name' => 'Active Effect ' . uniqid(),
            'slug' => 'active-' . uniqid(),
            'description' => 'Active effect description',
            'thumbnail_url' => 'https://example.com/thumb.png',
            'preview_video_url' => 'https://example.com/preview.mp4',
            'is_premium' => false,
            'is_active' => true,
        ]);

        $inactive = Effect::query()->create([
            'name' => 'Inactive Effect ' . uniqid(),
            'slug' => 'inactive-' . uniqid(),
            'description' => 'Inactive effect description',
            'is_premium' => false,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/effects');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $response->assertJsonFragment(['slug' => $active->slug]);
        $response->assertJsonMissing(['slug' => $inactive->slug]);
    }

    public function test_effect_show_by_slug_returns_effect(): void
    {
        $effect = Effect::query()->create([
            'name' => 'Show Effect ' . uniqid(),
            'slug' => 'show-' . uniqid(),
            'description' => 'Show effect description',
            'is_premium' => true,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/effects/' . $effect->slug);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonPath('data.slug', $effect->slug);
        $response->assertJsonPath('data.is_premium', true);
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

