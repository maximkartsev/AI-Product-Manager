<?php

namespace Database\Seeders;

use App\Models\Effect;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class EffectsSeeder extends Seeder
{
    /**
     * Seed the central effects catalog (idempotent).
     */
    public function run(): void
    {
        $disk = (string) config('filesystems.default', 's3');
        $root = base_path('resources/comfyui/workflows');
        if (!File::isDirectory($root)) {
            return;
        }

        $folders = File::directories($root);
        sort($folders);

        foreach ($folders as $folder) {
            $infoPath = $folder . DIRECTORY_SEPARATOR . 'info.json';
            if (!File::exists($infoPath)) {
                continue;
            }

            $infoRaw = File::get($infoPath);
            $info = json_decode($infoRaw, true);
            if (!is_array($info)) {
                $this->command?->warn("Invalid info.json (skipped): {$infoPath}");
                continue;
            }

            $slug = isset($info['slug']) ? trim((string) $info['slug']) : '';
            $name = isset($info['name']) ? trim((string) $info['name']) : '';
            if ($slug === '' || $name === '') {
                $this->command?->warn("Missing slug or name in info.json (skipped): {$infoPath}");
                continue;
            }

            $description = isset($info['description']) ? trim((string) $info['description']) : null;
            $description = $description !== '' ? $description : null;
            $isPremium = $this->parseBool($info['premium'] ?? $info['is_premium'] ?? null, false);
            $isNew = $this->parseBool($info['is_new'] ?? null, false);
            $isActive = $this->parseBool($info['is_active'] ?? null, true);
            $creditsCost = $this->parseFloat($info['price'] ?? $info['credits_cost'] ?? null);
            $processingEstimate = $this->parseFloat($info['processing_time_estimate'] ?? null);
            $popularityScore = $this->parseInt($info['popularity_score'] ?? null) ?? 0;
            $sortOrder = $this->parseInt($info['sort_order'] ?? null) ?? 0;
            $type = isset($info['type']) ? trim((string) $info['type']) : '';
            $type = $type !== '' ? $type : 'transform';
            $tags = $this->parseTags($info['tags'] ?? null);

            $workflowFullPath = $this->findWorkflowJson($folder);
            if (!$workflowFullPath) {
                $this->command?->warn("No workflow JSON found (skipped): {$folder}");
                continue;
            }
            $workflowPath = $this->relativePath($workflowFullPath);
            if (!$workflowPath) {
                continue;
            }

            $assetMeta = $this->uploadEffectAssets($slug, $folder, $disk);

            Effect::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                    'tags' => !empty($tags) ? array_values($tags) : null,
                    'type' => $type,
                    'preview_url' => null,
                    'thumbnail_url' => $assetMeta['thumbnail_url'] ?? null,
                    'preview_video_url' => $assetMeta['preview_video_url'] ?? null,
                    'parameters' => null,
                    'default_values' => null,
                    'credits_cost' => $creditsCost ?? ($isPremium ? 5.0 : 2.0),
                    'processing_time_estimate' => $processingEstimate,
                    'popularity_score' => $popularityScore,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'is_premium' => $isPremium,
                    'is_new' => $isNew,
                    'comfyui_workflow_path' => $workflowPath,
                    'comfyui_input_path_placeholder' => '__INPUT_PATH__',
                    'output_extension' => 'mp4',
                    'output_mime_type' => 'video/mp4',
                    'output_node_id' => '3',
                ],
            );
        }
    }

    /**
     * @return array{thumbnail_url?: string, preview_video_url?: string}
     */
    private function uploadEffectAssets(string $slug, string $assetDir, string $disk): array
    {
        if (!File::isDirectory($assetDir)) {
            return [];
        }

        $images = [];
        $videos = [];
        foreach (File::files($assetDir) as $file) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                $images[] = $file;
                continue;
            }
            if (in_array($ext, ['mp4', 'mov', 'webm'], true)) {
                $videos[] = $file;
            }
        }

        usort($images, fn ($a, $b) => strcmp($a->getFilename(), $b->getFilename()));
        usort($videos, fn ($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

        $data = [];
        if (!empty($images)) {
            $file = $images[0];
            $filename = $file->getFilename();
            $path = sprintf('effects/thumbnails/%s/%s', $slug, $filename);
            Storage::disk($disk)->put($path, File::get($file->getPathname()), [
                'visibility' => 'private',
                'ContentType' => $this->contentTypeForExtension(strtolower($file->getExtension())),
            ]);
            $data['thumbnail_url'] = Storage::disk($disk)->url($path);
        }

        if (!empty($videos)) {
            $file = $videos[0];
            $filename = $file->getFilename();
            $path = sprintf('effects/previews/%s/%s', $slug, $filename);
            Storage::disk($disk)->put($path, File::get($file->getPathname()), [
                'visibility' => 'private',
                'ContentType' => $this->contentTypeForExtension(strtolower($file->getExtension())),
            ]);
            $data['preview_video_url'] = Storage::disk($disk)->url($path);
        }

        return $data;
    }

    private function findWorkflowJson(string $folder): ?string
    {
        $jsonFiles = [];
        foreach (File::files($folder) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            if (strtolower($file->getFilename()) === 'info.json') {
                continue;
            }
            $jsonFiles[] = $file;
        }

        usort($jsonFiles, fn ($a, $b) => strcmp($a->getFilename(), $b->getFilename()));
        return !empty($jsonFiles) ? $jsonFiles[0]->getPathname() : null;
    }

    private function relativePath(string $fullPath): ?string
    {
        $basePath = str_replace('\\', '/', base_path());
        $full = str_replace('\\', '/', $fullPath);
        if (!str_starts_with($full, $basePath . '/')) {
            return null;
        }
        return substr($full, strlen($basePath) + 1);
    }

    private function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }
            return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
        }
        return $default;
    }

    private function parseInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    private function parseFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return string[]
     */
    private function parseTags(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $raw = [];
        if (is_string($value)) {
            $raw = preg_split('/[,;]/', $value) ?: [];
        } elseif (is_array($value)) {
            $raw = $value;
        } else {
            return [];
        }

        $tags = [];
        $seen = [];
        foreach ($raw as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $trimmed = trim((string) $tag);
            if ($trimmed === '') {
                continue;
            }
            $key = strtolower($trimmed);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tags[] = $trimmed;
        }

        return $tags;
    }

    private function contentTypeForExtension(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'video/mp4',
        };
    }
}

