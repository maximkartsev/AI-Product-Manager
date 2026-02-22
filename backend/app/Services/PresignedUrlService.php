<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class PresignedUrlService
{
    private function resolveS3Client(string $disk)
    {
        $diskInstance = Storage::disk($disk);
        if (method_exists($diskInstance, 'getClient')) {
            return $diskInstance->getClient();
        }

        $adapter = $diskInstance->getAdapter();
        if (method_exists($adapter, 'getClient')) {
            return $adapter->getClient();
        }

        throw new \RuntimeException("Disk {$disk} does not expose an S3 client.");
    }

    private function resolveBucket(string $disk): string
    {
        $bucket = config("filesystems.disks.{$disk}.bucket");
        if (!is_string($bucket) || $bucket === '') {
            throw new \RuntimeException("Bucket is not configured for disk {$disk}.");
        }
        return $bucket;
    }

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

    public function createMultipartUpload(string $disk, string $key, ?string $contentType = null): string
    {
        $client = $this->resolveS3Client($disk);
        $bucket = $this->resolveBucket($disk);
        $params = [
            'Bucket' => $bucket,
            'Key' => $key,
        ];
        if ($contentType) {
            $params['ContentType'] = $contentType;
        }

        $result = $client->createMultipartUpload($params);
        return (string) $result['UploadId'];
    }

    /**
     * @return array<int, array{part_number:int, url:string}>
     */
    public function createMultipartUploadPartUrls(
        string $disk,
        string $key,
        string $uploadId,
        int $partCount,
        int $ttlSeconds
    ): array {
        if ($partCount < 1) {
            throw new \RuntimeException('Multipart upload must have at least one part.');
        }
        if ($ttlSeconds <= 0) {
            throw new \RuntimeException('Presigned URL TTL must be positive.');
        }

        $client = $this->resolveS3Client($disk);
        $bucket = $this->resolveBucket($disk);
        $urls = [];
        for ($partNumber = 1; $partNumber <= $partCount; $partNumber++) {
            $cmd = $client->getCommand('UploadPart', [
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ]);
            $request = $client->createPresignedRequest($cmd, "+{$ttlSeconds} seconds");
            $urls[] = [
                'part_number' => $partNumber,
                'url' => (string) $request->getUri(),
            ];
        }
        return $urls;
    }

    public function completeMultipartUpload(string $disk, string $key, string $uploadId, array $parts): void
    {
        if (empty($parts)) {
            throw new \RuntimeException('Multipart upload parts are required.');
        }
        $client = $this->resolveS3Client($disk);
        $bucket = $this->resolveBucket($disk);

        $normalized = array_map(function ($part) {
            $etag = $part['ETag'] ?? $part['etag'] ?? null;
            $partNumber = $part['PartNumber'] ?? $part['part_number'] ?? null;
            return [
                'ETag' => is_string($etag) ? trim($etag, '"') : '',
                'PartNumber' => (int) $partNumber,
            ];
        }, $parts);

        usort($normalized, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        $client->completeMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $normalized],
        ]);
    }

    public function abortMultipartUpload(string $disk, string $key, string $uploadId): void
    {
        $client = $this->resolveS3Client($disk);
        $bucket = $this->resolveBucket($disk);
        $client->abortMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);
    }
}
