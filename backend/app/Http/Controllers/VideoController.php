<?php

namespace App\Http\Controllers;

use App\Models\Effect;
use App\Models\File;
use App\Models\GalleryVideo;
use App\Models\TokenWallet;
use App\Models\Video;
use App\Services\PresignedUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VideoController extends BaseController
{
    private const DEFAULT_ALLOWED_MIME_TYPES = [
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/x-matroska',
    ];

    public function createUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_id' => 'numeric|required|exists:effects,id',
            'mime_type' => 'string|required|max:255',
            'size' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
            'file_hash' => 'string|nullable|max:128',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $effect = Effect::query()->find((int) $request->input('effect_id'));
        if (!$effect) {
            return $this->sendError('Effect not found.', [], 404);
        }

        $tokenCost = (int) ceil((float) $effect->credits_cost);
        if ($tokenCost > 0) {
            $tenantId = (string) tenant()->getKey();
            $wallet = TokenWallet::query()->firstOrCreate(
                ['tenant_id' => $tenantId],
                ['user_id' => (int) $request->user()->id, 'balance' => 0]
            );

            if ((int) $wallet->user_id !== (int) $request->user()->id) {
                return $this->sendError('Token wallet user mismatch.', [], 500);
            }

            if ((int) $wallet->balance < $tokenCost) {
                return $this->sendError('Insufficient tokens.', [
                    'required_tokens' => $tokenCost,
                    'balance' => (int) $wallet->balance,
                ], 422);
            }
        }

        $mimeType = strtolower((string) $request->input('mime_type'));
        $allowed = (array) config('services.comfyui.allowed_mime_types', self::DEFAULT_ALLOWED_MIME_TYPES);
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
            'user_id' => (int) $request->user()->id,
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

    public function index(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $query = Video::query()->where('user_id', (int) $user->id);

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['title', 'status']);

        $orderStr = $request->get('order', 'created_at:desc');

        $filters = $this->extractFilters($request, Video::class);
        $this->addFiltersCriteria($query, $filters, Video::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        $fileIds = $items
            ->flatMap(fn ($video) => [$video->original_file_id, $video->processed_file_id])
            ->filter()
            ->unique()
            ->values();
        $filesById = $fileIds->isEmpty()
            ? collect()
            : File::withTrashed()->whereIn('id', $fileIds)->get()->keyBy('id');

        $effectIds = $items->pluck('effect_id')->filter()->unique()->values();
        $effectsById = $effectIds->isEmpty()
            ? collect()
            : Effect::query()->whereIn('id', $effectIds)->get()->keyBy('id');

        $payloadItems = $items->map(function ($video) use ($filesById, $effectsById, $presigned, $ttlSeconds) {
            $originalFile = $video->original_file_id ? $filesById->get($video->original_file_id) : null;
            $processedFile = $video->processed_file_id ? $filesById->get($video->processed_file_id) : null;

            $originalFileUrl = $this->resolveFileUrl($originalFile, $presigned, $ttlSeconds, false);
            $processedFileUrl = $this->resolveFileUrl($processedFile, $presigned, $ttlSeconds, true);

            $error = null;
            $details = $video->processing_details;
            if (is_array($details)) {
                $raw = $details['error'] ?? null;
                if (is_string($raw) && trim($raw) !== '') {
                    $error = trim($raw);
                }
            }
            if (!$error && $video->status === 'failed') {
                $error = 'Processing failed.';
            }

            $effect = null;
            if ($video->effect_id) {
                $effectModel = $effectsById->get($video->effect_id);
                if ($effectModel) {
                    $effect = [
                        'id' => $effectModel->id,
                        'slug' => $effectModel->slug,
                        'name' => $effectModel->name,
                        'description' => $effectModel->description,
                        'type' => $effectModel->type,
                        'is_premium' => $effectModel->is_premium,
                    ];
                }
            }

            $payload = $video->toArray();
            $payload['original_file_url'] = $originalFileUrl;
            $payload['processed_file_url'] = $processedFileUrl;
            $payload['error'] = $error;
            $payload['effect'] = $effect;

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
            'filters' => $filters,
        ];

        return $this->sendResponse($response, 'Videos retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_id' => 'numeric|required|exists:effects,id',
            'original_file_id' => 'numeric|required',
            'title' => 'string|nullable|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $file = File::query()->find((int) $request->input('original_file_id'));
        if (!$file) {
            return $this->sendError('File not found.', [], 404);
        }

        if ((int) $file->user_id !== (int) $request->user()->id) {
            return $this->sendError('File ownership mismatch.', [], 403);
        }

        $expiresAt = data_get($file->metadata, 'expires_at');
        if ($expiresAt && now()->gte(\Carbon\Carbon::parse($expiresAt))) {
            return $this->sendError('File has expired.', [], 422);
        }

        $effect = Effect::query()->find((int) $request->input('effect_id'));
        if (!$effect) {
            return $this->sendError('Effect not found.', [], 404);
        }

        $video = Video::query()->create([
            'tenant_id' => (string) tenant()->getKey(),
            'user_id' => (int) $request->user()->id,
            'effect_id' => $effect->id,
            'original_file_id' => $file->id,
            'title' => $request->input('title'),
            'status' => 'queued',
            'is_public' => false,
        ]);

        return $this->sendResponse($video, 'Video created');
    }

    public function show(Request $request, int $id, PresignedUrlService $presigned): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $video = Video::query()->find($id);
        if (!$video) {
            return $this->sendError('Video not found.', [], 404);
        }

        if ((int) $video->user_id !== (int) $user->id) {
            return $this->sendError('Video ownership mismatch.', [], 403);
        }

        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        $originalFileUrl = null;
        if ($video->original_file_id) {
            $file = File::withTrashed()->find((int) $video->original_file_id);
            if ($file) {
                if ($file->url) {
                    $originalFileUrl = (string) $file->url;
                } elseif ($file->disk && $file->path) {
                    try {
                        $originalFileUrl = $presigned->downloadUrl($file->disk, $file->path, $ttlSeconds);
                    } catch (\Throwable $e) {
                        // ignore URL generation issues
                    }
                }
            }
        }

        $processedFileUrl = null;
        if ($video->processed_file_id) {
            $file = File::withTrashed()->find((int) $video->processed_file_id);
            if ($file) {
                if ($file->disk && $file->path) {
                    try {
                        $processedFileUrl = $presigned->downloadUrl($file->disk, $file->path, $ttlSeconds);
                    } catch (\Throwable $e) {
                        // ignore URL generation issues
                    }
                }
                if (!$processedFileUrl && $file->url) {
                    $processedFileUrl = (string) $file->url;
                }
            }
        }

        $error = null;
        $details = $video->processing_details;
        if (is_array($details)) {
            $raw = $details['error'] ?? null;
            if (is_string($raw) && trim($raw) !== '') {
                $error = trim($raw);
            }
        }
        if (!$error && $video->status === 'failed') {
            $error = 'Processing failed.';
        }

        $payload = $video->toArray();
        $payload['original_file_url'] = $originalFileUrl;
        $payload['processed_file_url'] = $processedFileUrl;
        $payload['error'] = $error;

        return $this->sendResponse($payload, 'Video retrieved');
    }

    public function publish(Request $request, Video $video): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'string|nullable|max:255',
            'tags' => 'array|nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        if ((int) $video->user_id !== (int) $request->user()->id) {
            return $this->sendError('Video ownership mismatch.', [], 403);
        }

        if ($video->status !== 'completed') {
            return $this->sendError('Video is not processed yet.', [], 422);
        }

        $file = $video->processed_file_id ? File::query()->find($video->processed_file_id) : null;
        if (!$file) {
            return $this->sendError('Processed file is missing.', [], 422);
        }

        $processedUrl = $file->url;
        if (!$processedUrl && $file->disk && $file->path) {
            $processedUrl = Storage::disk($file->disk)->url($file->path);
        }
        if (!$processedUrl) {
            return $this->sendError('Processed file is missing.', [], 422);
        }

        $thumbnailUrl = data_get($video->processing_details, 'thumbnail_url');
        if (!is_string($thumbnailUrl) || trim($thumbnailUrl) === '') {
            $thumbnailPath = data_get($video->processing_details, 'thumbnail_path')
                ?? data_get($video->processing_details, 'thumbnail_key');
            if (is_string($thumbnailPath) && trim($thumbnailPath) !== '') {
                $thumbnailPath = trim($thumbnailPath);
                if (Str::startsWith($thumbnailPath, ['http://', 'https://'])) {
                    $thumbnailUrl = $thumbnailPath;
                } else {
                    $disk = $file->disk ?: (string) config('filesystems.default', 's3');
                    $thumbnailUrl = Storage::disk($disk)->url(ltrim($thumbnailPath, '/'));
                }
            } else {
                $thumbnailUrl = null;
            }
        }

        $title = (string) ($request->input('title') ?: $video->title ?: 'Untitled');
        $tags = $request->input('tags');

        $gallery = GalleryVideo::withTrashed()->updateOrCreate([
            'tenant_id' => (string) $video->tenant_id,
            'video_id' => $video->id,
        ], [
            'user_id' => $video->user_id,
            'effect_id' => $video->effect_id,
            'title' => $title,
            'tags' => $tags,
            'is_public' => true,
            'processed_file_url' => $processedUrl,
            'thumbnail_url' => $thumbnailUrl,
        ]);

        if ($gallery->trashed()) {
            $gallery->restore();
        }

        $video->is_public = true;
        $video->save();

        return $this->sendResponse($gallery, 'Video published');
    }

    public function unpublish(Request $request, Video $video): JsonResponse
    {
        if ((int) $video->user_id !== (int) $request->user()->id) {
            return $this->sendError('Video ownership mismatch.', [], 403);
        }

        GalleryVideo::query()
            ->where('tenant_id', (string) $video->tenant_id)
            ->where('video_id', $video->id)
            ->update(['is_public' => false]);

        $video->is_public = false;
        $video->save();

        return $this->sendResponse($video, 'Video unpublished');
    }

    private function resolveFileUrl(?File $file, PresignedUrlService $presigned, int $ttlSeconds, bool $preferPresigned): ?string
    {
        if (!$file) {
            return null;
        }

        $disk = $file->disk;
        $path = $file->path;

        if ($preferPresigned && $disk && $path) {
            try {
                return $presigned->downloadUrl($disk, $path, $ttlSeconds);
            } catch (\Throwable $e) {
                // ignore URL generation issues
            }
        }

        if ($file->url) {
            return (string) $file->url;
        }

        if (!$preferPresigned && $disk && $path) {
            try {
                return $presigned->downloadUrl($disk, $path, $ttlSeconds);
            } catch (\Throwable $e) {
                // ignore URL generation issues
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
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-matroska' => 'mkv',
            default => 'bin',
        };
    }
}
