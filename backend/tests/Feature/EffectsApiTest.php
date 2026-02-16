<?php

namespace Tests\Feature;

use App\Models\Effect;
use App\Models\Workflow;
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

    public function test_effect_show_missing_returns_404_envelope(): void
    {
        $response = $this->getJson('/api/effects/does-not-exist-' . uniqid());

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }
}

