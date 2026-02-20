<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\PresignedUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FileController extends BaseController
{
    private const IMAGE_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'image/avif',
    ];

    private const VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/x-matroska',
    ];

    public function index(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $query = File::query()->where('user_id', (int) $user->id);

        $kind = $request->get('kind');
        if ($kind === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($kind === 'video') {
            $query->where('mime_type', 'like', 'video/%');
        }

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['original_filename', 'path']);

        $orderStr = $request->get('order', 'created_at:desc');

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        $payloadItems = $items->map(function ($file) use ($presigned, $ttlSeconds) {
            $payload = $file->toArray();
            $payload['download_url'] = $this->resolveFileUrl($file, $presigned, $ttlSeconds);
            return $payload;
        });

        $response = [
            'items' => $payloadItems,
            'totalItems' => $totalRows,
            'totalPages' => (int) ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => [],
        ];

        return $this->sendResponse($response, 'Files retrieved');
    }

    public function createUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'string|required|in:image,video',
            'mime_type' => 'string|required|max:255',
            'size' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
            'file_hash' => 'string|nullable|max:128',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $kind = (string) $request->input('kind');
        $mimeType = strtolower((string) $request->input('mime_type'));
        $allowed = $kind === 'image' ? self::IMAGE_MIME_TYPES : self::VIDEO_MIME_TYPES;

        if (!in_array($mimeType, $allowed, true)) {
            return $this->sendError('Unsupported mime type.', [
                'mime_type' => $mimeType,
            ], 422);
        }

        $maxBytes = (int) config('services.comfyui.upload_max_bytes', 1024 * 1024 * 1024);
        $size = (int) $request->input('size');
        if ($size > $maxBytes) {
            return $this->sendError('File too large.', [
                'max_bytes' => $maxBytes,
            ], 422);
        }

        $originalFilename = (string) $request->input('original_filename');
        if (!$this->isSafeFilename($originalFilename)) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $extension = $this->resolveExtension($originalFilename, $mimeType);
        $tenantId = (string) tenant()->getKey();
        $path = sprintf(
            'tenants/%s/uploads/%s.%s',
            $tenantId,
            (string) Str::uuid(),
            $extension
        );

        $disk = (string) config('filesystems.default', 's3');
        $file = File::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => (int) $user->id,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'original_filename' => $originalFilename,
            'file_hash' => $request->input('file_hash'),
        ]);

        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        try {
            $upload = $presigned->uploadUrl($disk, $path, $ttlSeconds, $mimeType);
        } catch (\Throwable $e) {
            $file->delete();
            return $this->sendError('Upload URL generation failed.', [], 500);
        }

        return $this->sendResponse([
            'file' => $file,
            'upload_url' => $upload['url'] ?? null,
            'upload_headers' => $upload['headers'] ?? [],
            'expires_in' => $ttlSeconds,
        ], 'Upload initialized');
    }

    private function resolveFileUrl(?File $file, PresignedUrlService $presigned, int $ttlSeconds): ?string
    {
        if (!$file) {
            return null;
        }

        $disk = $file->disk;
        $path = $file->path;

        if ($file->url) {
            return (string) $file->url;
        }

        if ($disk && $path) {
            try {
                return $presigned->downloadUrl($disk, $path, $ttlSeconds);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function isSafeFilename(string $filename): bool
    {
        if ($filename === '') {
            return false;
        }

        if (Str::contains($filename, ['..', '/', '\\'])) {
            return false;
        }

        return basename($filename) === $filename;
    }

    private function resolveExtension(string $filename, string $mimeType): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $extension;
        }

        return match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-matroska' => 'mkv',
            default => 'bin',
        };
    }
}
