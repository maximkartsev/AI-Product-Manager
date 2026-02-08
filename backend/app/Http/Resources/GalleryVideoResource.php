<?php

namespace App\Http\Resources;

use App\Services\PresignedUrlService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GalleryVideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $effect = $this->relationLoaded('effect') ? $this->effect : null;
        $category = $effect && $effect->relationLoaded('category') ? $effect->category : null;
        $effectName = $effect?->name;
        $displayTitle = is_string($effectName) && trim($effectName) !== '' ? trim($effectName) : null;

        return [
            'id' => $this->id,
            'title' => $displayTitle,
            'tags' => $this->tags,
            'input_payload' => $this->input_payload,
            'created_at' => $this->created_at,
            'processed_file_url' => $this->presignAsset($this->processed_file_url),
            'thumbnail_url' => $this->presignAsset($this->thumbnail_url),
            'effect' => $effect ? [
                'id' => $effect->id,
                'slug' => $effect->slug,
                'name' => $effect->name,
                'description' => $effect->description,
                'type' => $effect->type,
                'is_premium' => $effect->is_premium,
                'category' => $category ? [
                    'id' => $category->id,
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'description' => $category->description,
                ] : null,
            ] : null,
        ];
    }

    private function presignAsset(?string $value): ?string
    {
        $raw = $value ? trim($value) : '';
        if ($raw === '') {
            return $value;
        }

        $disk = (string) config('filesystems.default', 's3');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        $path = $this->extractAssetPath($raw, $disk);
        if (!$path) {
            return $value;
        }

        try {
            $presigned = app(PresignedUrlService::class);
            return $presigned->downloadUrl($disk, $path, $ttlSeconds);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function extractAssetPath(string $value, string $disk): ?string
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (!Str::startsWith($raw, ['http://', 'https://'])) {
            return $this->normalizeAssetPath($raw);
        }

        $base = '';
        try {
            $base = (string) Storage::disk($disk)->url('');
        } catch (\Throwable $e) {
            $base = '';
        }
        $base = $base !== '' ? rtrim($base, '/') . '/' : '';

        if ($base !== '' && Str::startsWith($raw, $base)) {
            return $this->normalizeAssetPath(substr($raw, strlen($base)));
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        return $this->normalizeAssetPath($path);
    }

    private function normalizeAssetPath(string $path): ?string
    {
        $trimmed = ltrim($path, '/');
        if (Str::startsWith($trimmed, 'storage/')) {
            $trimmed = substr($trimmed, strlen('storage/'));
        }
        if (Str::startsWith($trimmed, 'bp-media/')) {
            $trimmed = substr($trimmed, strlen('bp-media/'));
        }

        return $trimmed ?: null;
    }
}
