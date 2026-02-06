<?php

namespace Database\Seeders;

use App\Models\Effect;
use Illuminate\Database\Seeder;

class EffectsSeeder extends Seeder
{
    /**
     * Seed the central effects catalog (idempotent).
     */
    public function run(): void
    {
        $effects = [
            [
                'name' => 'Anime Glow Up',
                'slug' => 'anime-glow-up',
                'description' => 'Anime-inspired glow-up with vibrant color and clean lines.',
                'is_premium' => true,
                'is_new' => true,
                'sort_order' => 10,
                'popularity_score' => 95,
            ],
            [
                'name' => 'Cinematic Color Grade',
                'slug' => 'cinematic-color-grade',
                'description' => 'Film-like contrast and color for a cinematic look.',
                'is_premium' => true,
                'is_new' => false,
                'sort_order' => 20,
                'popularity_score' => 90,
            ],
            [
                'name' => 'Neon Outline',
                'slug' => 'neon-outline',
                'description' => 'Add neon edges and glowing outlines for high-energy clips.',
                'is_premium' => true,
                'is_new' => false,
                'sort_order' => 30,
                'popularity_score' => 88,
            ],
            [
                'name' => 'Clean Beauty',
                'slug' => 'clean-beauty',
                'description' => 'Soft skin smoothing and subtle enhancement for portrait video.',
                'is_premium' => true,
                'is_new' => true,
                'sort_order' => 40,
                'popularity_score' => 85,
            ],
        ];

        foreach ($effects as $effect) {
            $isPremium = (bool) ($effect['is_premium'] ?? false);
            Effect::query()->updateOrCreate(
                ['slug' => $effect['slug']],
                [
                    'name' => $effect['name'],
                    'description' => $effect['description'] ?? null,
                    'type' => $effect['type'] ?? 'transform',
                    'preview_url' => $effect['preview_url'] ?? null,
                    'thumbnail_url' => $effect['thumbnail_url'] ?? null,
                    'preview_video_url' => $effect['preview_video_url'] ?? null,
                    'parameters' => $effect['parameters'] ?? null,
                    'default_values' => $effect['default_values'] ?? null,
                    'credits_cost' => $effect['credits_cost'] ?? ($isPremium ? 5.0 : 2.0),
                    'processing_time_estimate' => $effect['processing_time_estimate'] ?? null,
                    'popularity_score' => $effect['popularity_score'] ?? 0,
                    'sort_order' => $effect['sort_order'] ?? 0,
                    'is_active' => $effect['is_active'] ?? true,
                    'is_premium' => $effect['is_premium'] ?? true,
                    'is_new' => $effect['is_new'] ?? false,
                    'comfyui_workflow_path' =>
                        $effect['comfyui_workflow_path']
                        ?? 'resources/comfyui/workflows/1-bunny_character/bunny_character.json',
                    'comfyui_input_path_placeholder' => $effect['comfyui_input_path_placeholder'] ?? '__INPUT_PATH__',
                    'output_extension' => $effect['output_extension'] ?? 'mp4',
                    'output_mime_type' => $effect['output_mime_type'] ?? 'video/mp4',
                    'output_node_id' => $effect['output_node_id'] ?? '3',
                ],
            );
        }
    }
}

