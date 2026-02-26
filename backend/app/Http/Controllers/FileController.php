<?php

namespace App\Http\Controllers;

use App\Models\Effect;
use App\Models\File;
use App\Models\TokenWallet;
use App\Services\PresignedUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            'effect_id' => 'numeric|required|exists:effects,id',
            'upload_id' => 'string|required|max:128',
            'property_key' => 'string|required|max:128',
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

        $effect = Effect::query()->with('workflow')->find((int) $request->input('effect_id'));
        if (!$effect) {
            return $this->sendError('Effect not found.', [], 404);
        }
        if ($effect->publication_status === 'development' && !(bool) ($user->is_admin ?? false)) {
            return $this->sendError('Effect not found.', [], 404);
        }
        if (!$effect->workflow) {
            return $this->sendError('Effect workflow not configured.', [], 422);
        }

        $tokenCost = (int) ceil((float) $effect->credits_cost);
        if ($tokenCost > 0) {
            $tenantId = (string) tenant()->getKey();
            $wallet = TokenWallet::query()->firstOrCreate(
                ['tenant_id' => $tenantId],
                ['user_id' => (int) $user->id, 'balance' => 0]
            );

            if ((int) $wallet->user_id !== (int) $user->id) {
                return $this->sendError('Token wallet user mismatch.', [], 500);
            }

            if ((int) $wallet->balance < $tokenCost) {
                return $this->sendError('Insufficient tokens.', [
                    'required_tokens' => $tokenCost,
                    'balance' => (int) $wallet->balance,
                ], 422);
            }
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

        $uploadId = (string) $request->input('upload_id');
        if ($uploadId === '' || Str::contains($uploadId, ['..', '/', '\\'])) {
            return $this->sendError('Invalid upload id.', [], 422);
        }

        $propertyKey = (string) $request->input('property_key');
        if ($propertyKey === '' || Str::contains($propertyKey, ['..', '/', '\\'])) {
            return $this->sendError('Invalid property key.', [], 422);
        }

        $targetProp = null;
        $properties = $effect->workflow->properties ?? [];
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            if (($prop['key'] ?? null) === $propertyKey) {
                $targetProp = $prop;
                break;
            }
        }

        if (!$targetProp) {
            return $this->sendError('Unsupported property.', [], 422);
        }
        if (empty($targetProp['user_configurable']) || !empty($targetProp['is_primary_input'])) {
            return $this->sendError('Property is not configurable.', [], 422);
        }

        $propType = (string) ($targetProp['type'] ?? 'text');
        if (!in_array($propType, ['image', 'video'], true)) {
            return $this->sendError('Unsupported property type.', [], 422);
        }
        if ($propType !== $kind) {
            return $this->sendError('Property type mismatch.', [
                'expected' => $propType,
                'provided' => $kind,
            ], 422);
        }

        $extension = $this->resolveExtension($originalFilename, $mimeType);
        $tenantId = (string) tenant()->getKey();
        $basePath = sprintf('tenants/%s/runs/%s/assets/%s', $tenantId, $uploadId, $propertyKey);
        $path = sprintf('%s.%s', $basePath, $extension);

        $disk = (string) config('filesystems.default', 's3');

        $existingFiles = File::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $user->id)
            ->where('path', 'like', $basePath . '.%')
            ->get();
        foreach ($existingFiles as $existing) {
            if ($existing->disk && $existing->path) {
                try {
                    Storage::disk($existing->disk)->delete($existing->path);
                } catch (\Throwable $e) {
                    // ignore delete failures
                }
            }
            $existing->delete();
        }

        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);
        $expiresAt = now()->addSeconds(max($ttlSeconds, 3600));
        $file = File::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => (int) $user->id,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'original_filename' => $originalFilename,
            'file_hash' => $request->input('file_hash'),
            'metadata' => [
                'expires_at' => $expiresAt->toIso8601String(),
                'upload_id' => $uploadId,
                'property_key' => $propertyKey,
                'effect_id' => $effect->id,
            ],
        ]);

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
