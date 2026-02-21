<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\PresignedUrlService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Effect extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $data = parent::toArray($request);
        $category = $this->relationLoaded('category') ? $this->category : null;
        $workflow = $this->relationLoaded('workflow') ? $this->workflow : null;
        $configurableProps = [];
        $overrides = is_array($this->property_overrides) ? $this->property_overrides : [];
        if ($workflow && is_array($workflow->properties)) {
            foreach ($workflow->properties as $prop) {
                if (!is_array($prop)) {
                    continue;
                }
                if (empty($prop['user_configurable']) || !empty($prop['is_primary_input'])) {
                    continue;
                }
                $key = $prop['key'] ?? null;
                if (!is_string($key) || trim($key) === '') {
                    continue;
                }
                $defaultValue = $prop['default_value'] ?? null;
                if (array_key_exists($key, $overrides)) {
                    $defaultValue = $overrides[$key];
                }
                $configurableProps[] = [
                    'key' => $key,
                    'name' => $prop['name'] ?? null,
                    'description' => $prop['description'] ?? null,
                    'type' => $prop['type'] ?? 'text',
                    'required' => (bool) ($prop['required'] ?? false),
                    'default_value' => $defaultValue,
                ];
            }
        }

        $data['thumbnail_url'] = $this->presignEffectAsset($data['thumbnail_url'] ?? null);
        $data['preview_video_url'] = $this->presignEffectAsset($data['preview_video_url'] ?? null);
        $data['category'] = $category ? [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
        ] : null;
        $data['configurable_properties'] = $configurableProps;

        return $data;
    }

    private function presignEffectAsset(?string $value): ?string
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
            $trimmed = ltrim($raw, '/');
            if (Str::startsWith($trimmed, 'bp-media/')) {
                $trimmed = substr($trimmed, strlen('bp-media/'));
            }
            return $trimmed ?: null;
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $trimmed = ltrim($path, '/');
        $basePath = '';
        try {
            $base = (string) Storage::disk($disk)->url('');
            $basePath = (string) parse_url($base, PHP_URL_PATH);
        } catch (\Throwable $e) {
            $basePath = '';
        }
        $basePath = $basePath !== '' ? trim($basePath, '/') . '/' : '';
        if ($basePath !== '' && Str::startsWith($trimmed, $basePath)) {
            $trimmed = substr($trimmed, strlen($basePath));
        }
        if (Str::startsWith($trimmed, 'bp-media/')) {
            $trimmed = substr($trimmed, strlen('bp-media/'));
        } elseif (Str::startsWith($trimmed, 'tenants/')) {
            return $trimmed;
        }

        return $trimmed ?: null;
    }
}
