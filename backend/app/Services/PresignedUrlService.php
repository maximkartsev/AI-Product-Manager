<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class PresignedUrlService
{
    public function downloadUrl(string $disk, string $path, int $ttlSeconds): string
    {
        if ($ttlSeconds <= 0) {
            throw new \RuntimeException('Presigned URL TTL must be positive.');
        }
        $expiration = now()->addSeconds($ttlSeconds);
        return Storage::disk($disk)->temporaryUrl($path, $expiration);
    }

    /**
     * @return array{url:string, headers:array}
     */
    public function uploadUrl(string $disk, string $path, int $ttlSeconds, ?string $contentType = null): array
    {
        if ($ttlSeconds <= 0) {
            throw new \RuntimeException('Presigned URL TTL must be positive.');
        }
        $expiration = now()->addSeconds($ttlSeconds);
        $options = [];

        if ($contentType) {
            $options['ContentType'] = $contentType;
        }

        $diskInstance = Storage::disk($disk);
        if (method_exists($diskInstance, 'temporaryUploadUrl')) {
            return $diskInstance->temporaryUploadUrl($path, $expiration, $options);
        }

        throw new \RuntimeException("Disk {$disk} does not support temporaryUploadUrl.");
    }
}
