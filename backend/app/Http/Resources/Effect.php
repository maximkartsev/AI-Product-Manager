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

        $data['thumbnail_url'] = $this->presignEffectAsset($data['thumbnail_url'] ?? null);
        $data['preview_video_url'] = $this->presignEffectAsset($data['preview_video_url'] ?? null);

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
            return ltrim($raw, '/');
        }

        $base = '';
        try {
            $base = (string) Storage::disk($disk)->url('');
        } catch (\Throwable $e) {
            $base = '';
        }
        $base = $base !== '' ? rtrim($base, '/') . '/' : '';

        if ($base !== '' && Str::startsWith($raw, $base)) {
            return ltrim(substr($raw, strlen($base)), '/');
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $trimmed = ltrim($path, '/');
        if (Str::startsWith($trimmed, 'bp-media/')) {
            $trimmed = substr($trimmed, strlen('bp-media/'));
        } elseif (Str::startsWith($trimmed, 'tenants/')) {
            return $trimmed;
        }

        return $trimmed ?: null;
    }
}
