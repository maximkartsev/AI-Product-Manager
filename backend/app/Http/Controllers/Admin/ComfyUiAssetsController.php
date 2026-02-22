<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ComfyUiAssetBundle;
use App\Models\ComfyUiAssetBundleFile;
use App\Models\ComfyUiAssetFile;
use App\Models\ComfyUiAssetAuditLog;
use App\Models\ComfyUiGpuFleet;
use App\Services\ComfyUiAssetAuditService;
use App\Services\PresignedUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ComfyUiAssetsController extends BaseController
{
    private const MULTIPART_MIN_PART_SIZE = 5 * 1024 * 1024;
    private const MULTIPART_TARGET_PART_SIZE = 100 * 1024 * 1024;
    private const MULTIPART_MAX_PARTS = 10000;

    private const ASSET_KIND_PATHS = [
        'checkpoint' => 'models/checkpoints',
        'diffusion_model' => 'models/diffusion_models',
        'lora' => 'models/loras',
        'vae' => 'models/vae',
        'embedding' => 'models/embeddings',
        'text_encoder' => 'models/text_encoders',
        'controlnet' => 'models/controlnet',
        'custom_node' => 'custom_nodes',
        'other' => 'models/other',
    ];

    public function filesIndex(Request $request): JsonResponse
    {
        $query = ComfyUiAssetFile::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);
        $query->select($fieldsToSelect);

        $searchFields = ['original_filename', 's3_key', 'kind', 'sha256', 'notes'];
        $this->addSearchCriteria($searchStr, $query, $searchFields);

        $orderStr = $request->get('order', 'id:desc');
        $filters = $this->extractFilters($request, ComfyUiAssetFile::class);
        $this->addFiltersCriteria($query, $filters, ComfyUiAssetFile::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ], 'ComfyUI asset files retrieved successfully');
    }

    public function createUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'string|required|in:' . implode(',', array_keys(self::ASSET_KIND_PATHS)),
            'mime_type' => 'string|required|max:255',
            'size_bytes' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
            'sha256' => 'string|required|max:128',
            'notes' => 'string|nullable|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $originalFilename = (string) $request->input('original_filename');
        if ($this->hasUnsafePathChars($originalFilename)) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $sha256 = strtolower(trim((string) $request->input('sha256')));
        $uploadPrefix = (string) config('services.comfyui.asset_upload_prefix', 'assets');
        $path = sprintf('%s/%s/%s', $uploadPrefix, $request->input('kind'), $sha256);
        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);
        $alreadyExists = ComfyUiAssetFile::query()
            ->where('kind', $request->input('kind'))
            ->where('sha256', $sha256)
            ->exists();

        try {
            $upload = $presigned->uploadUrl($disk, $path, $ttlSeconds, (string) $request->input('mime_type'));
        } catch (\Throwable $e) {
            return $this->sendError('Upload URL generation failed.', [], 500);
        }

        return $this->sendResponse([
            'path' => $path,
            'upload_url' => $upload['url'] ?? null,
            'upload_headers' => $upload['headers'] ?? [],
            'expires_in' => $ttlSeconds,
            'already_exists' => $alreadyExists,
        ], 'Upload initialized');
    }

    public function createMultipartUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'string|required|in:' . implode(',', array_keys(self::ASSET_KIND_PATHS)),
            'mime_type' => 'string|required|max:255',
            'size_bytes' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
            'sha256' => 'string|required|max:128',
            'notes' => 'string|nullable|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $originalFilename = (string) $request->input('original_filename');
        if ($this->hasUnsafePathChars($originalFilename)) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $sizeBytes = (int) $request->input('size_bytes');
        $sha256 = strtolower(trim((string) $request->input('sha256')));
        $uploadPrefix = (string) config('services.comfyui.asset_upload_prefix', 'assets');
        $key = sprintf('%s/%s/%s', $uploadPrefix, $request->input('kind'), $sha256);
        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);
        $alreadyExists = ComfyUiAssetFile::query()
            ->where('kind', $request->input('kind'))
            ->where('sha256', $sha256)
            ->exists();

        $partSize = max(self::MULTIPART_MIN_PART_SIZE, self::MULTIPART_TARGET_PART_SIZE);
        $partCount = (int) ceil($sizeBytes / $partSize);
        if ($partCount > self::MULTIPART_MAX_PARTS) {
            $partSize = (int) ceil($sizeBytes / self::MULTIPART_MAX_PARTS);
            $partSize = max($partSize, self::MULTIPART_MIN_PART_SIZE);
            $partCount = (int) ceil($sizeBytes / $partSize);
        }
        if ($partCount > self::MULTIPART_MAX_PARTS) {
            return $this->sendError('File is too large for multipart upload.', [], 422);
        }

        try {
            $uploadId = $presigned->createMultipartUpload(
                $disk,
                $key,
                (string) $request->input('mime_type')
            );
            $partUrls = $presigned->createMultipartUploadPartUrls(
                $disk,
                $key,
                $uploadId,
                $partCount,
                $ttlSeconds
            );
        } catch (\Throwable $e) {
            return $this->sendError('Upload URL generation failed.', [], 500);
        }

        return $this->sendResponse([
            'key' => $key,
            'upload_id' => $uploadId,
            'part_size' => $partSize,
            'part_urls' => $partUrls,
            'expires_in' => $ttlSeconds,
            'already_exists' => $alreadyExists,
        ], 'Multipart upload initialized');
    }

    public function completeMultipartUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_id' => 'string|required|max:255',
            'key' => 'string|required|max:2048',
            'parts' => 'array|required|min:1',
            'parts.*.part_number' => 'integer|required|min:1',
            'parts.*.etag' => 'string|required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $key = (string) $request->input('key');
        $uploadId = (string) $request->input('upload_id');
        $parts = (array) $request->input('parts');

        try {
            $presigned->completeMultipartUpload($disk, $key, $uploadId, $parts);
        } catch (\Throwable $e) {
            return $this->sendError('Multipart upload completion failed.', [], 500);
        }

        return $this->sendResponse([
            'key' => $key,
        ], 'Multipart upload completed');
    }

    public function abortMultipartUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_id' => 'string|required|max:255',
            'key' => 'string|required|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $key = (string) $request->input('key');
        $uploadId = (string) $request->input('upload_id');

        try {
            $presigned->abortMultipartUpload($disk, $key, $uploadId);
        } catch (\Throwable $e) {
            return $this->sendError('Multipart upload abort failed.', [], 500);
        }

        return $this->sendNoContent();
    }

    public function filesStore(Request $request, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'string|required|in:' . implode(',', array_keys(self::ASSET_KIND_PATHS)),
            'original_filename' => 'string|required|max:512',
            'content_type' => 'string|nullable|max:255',
            'size_bytes' => 'integer|nullable|min:1',
            'sha256' => 'string|required|max:128',
            'notes' => 'string|nullable|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        if ($this->hasUnsafePathChars((string) $request->input('original_filename'))) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $sha256 = strtolower(trim((string) $request->input('sha256')));
        $uploadPrefix = (string) config('services.comfyui.asset_upload_prefix', 'assets');
        $s3Key = sprintf('%s/%s/%s', $uploadPrefix, $request->input('kind'), $sha256);
        $notes = $request->input('notes');

        $asset = ComfyUiAssetFile::query()
            ->where('kind', $request->input('kind'))
            ->where('sha256', $sha256)
            ->first();

        if ($asset) {
            if ($notes !== null) {
                $asset->notes = $notes;
            }
            if (!$asset->original_filename) {
                $asset->original_filename = $request->input('original_filename');
            }
            if (!$asset->content_type && $request->input('content_type')) {
                $asset->content_type = $request->input('content_type');
            }
            if (!$asset->size_bytes && $request->input('size_bytes')) {
                $asset->size_bytes = $request->input('size_bytes');
            }
            if (!$asset->s3_key) {
                $asset->s3_key = $s3Key;
            }
            if (!$asset->uploaded_at) {
                $asset->uploaded_at = Carbon::now();
            }
            $asset->save();
        } else {
            $asset = ComfyUiAssetFile::query()->create([
                'kind' => $request->input('kind'),
                'original_filename' => $request->input('original_filename'),
                's3_key' => $s3Key,
                'content_type' => $request->input('content_type'),
                'size_bytes' => $request->input('size_bytes'),
                'sha256' => $sha256,
                'notes' => $notes,
                'uploaded_at' => Carbon::now(),
            ]);
        }

        $user = $request->user();
        $audit->log(
            'asset_uploaded',
            null,
            $asset->id,
            null,
            ['kind' => $asset->kind, 'sha256' => $asset->sha256],
            $user?->id,
            $user?->email
        );

        return $this->sendResponse($asset, 'ComfyUI asset file created successfully', [], 201);
    }

    public function filesUpdate(Request $request, $id, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $asset = ComfyUiAssetFile::query()->find($id);
        if (!$asset) {
            return $this->sendError('Asset not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'original_filename' => 'string|nullable|max:512',
            'notes' => 'string|nullable|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        if ($request->has('original_filename')) {
            $originalFilename = (string) $request->input('original_filename');
            if ($originalFilename !== '' && $this->hasUnsafePathChars($originalFilename)) {
                return $this->sendError('Invalid filename.', [], 422);
            }
            $asset->original_filename = $originalFilename;
        }

        if ($request->has('notes')) {
            $asset->notes = $request->input('notes');
        }

        $asset->save();

        $user = $request->user();
        $audit->log(
            'asset_updated',
            null,
            $asset->id,
            null,
            ['fields' => array_keys($request->only(['original_filename', 'notes']))],
            $user?->id,
            $user?->email
        );

        return $this->sendResponse($asset, 'ComfyUI asset file updated successfully');
    }

    public function bundlesIndex(Request $request): JsonResponse
    {
        $query = ComfyUiAssetBundle::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);
        $query->select($fieldsToSelect);

        $searchFields = ['bundle_id', 'name', 'notes'];
        $this->addSearchCriteria($searchStr, $query, $searchFields);

        $orderStr = $request->get('order', 'id:desc');
        $filters = $this->extractFilters($request, ComfyUiAssetBundle::class);
        $this->addFiltersCriteria($query, $filters, ComfyUiAssetBundle::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ], 'ComfyUI asset bundles retrieved successfully');
    }

    public function cleanupCandidates(Request $request): JsonResponse
    {
        $activeBundleIds = ComfyUiGpuFleet::query()
            ->whereNotNull('active_bundle_id')
            ->pluck('active_bundle_id')
            ->unique();

        $query = ComfyUiAssetBundle::query();

        if ($activeBundleIds->isNotEmpty()) {
            $query->whereNotIn('id', $activeBundleIds);
        }

        $bundles = $query->orderBy('id', 'desc')->get();
        $items = $bundles->map(function (ComfyUiAssetBundle $bundle) {
            return [
                'id' => $bundle->id,
                'bundle_id' => $bundle->bundle_id,
                's3_prefix' => $bundle->s3_prefix,
                'reason' => 'not_active_in_any_fleet',
            ];
        });

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $items->count(),
        ], 'Cleanup candidates retrieved successfully');
    }

    public function cleanupAssetCandidates(Request $request): JsonResponse
    {
        $bundleAssetIds = ComfyUiAssetBundleFile::query()
            ->pluck('asset_file_id')
            ->unique();

        $query = ComfyUiAssetFile::query();
        if ($bundleAssetIds->isNotEmpty()) {
            $query->whereNotIn('id', $bundleAssetIds);
        }

        $assets = $query->orderBy('id', 'desc')->get();
        $items = $assets->map(function (ComfyUiAssetFile $asset) {
            return [
                'id' => $asset->id,
                'kind' => $asset->kind,
                'original_filename' => $asset->original_filename,
                's3_key' => $asset->s3_key,
                'sha256' => $asset->sha256,
                'size_bytes' => $asset->size_bytes,
                'reason' => 'unreferenced',
            ];
        });

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $items->count(),
        ], 'Asset cleanup candidates retrieved successfully');
    }

    public function bundlesDestroy(Request $request, $id, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $bundle = ComfyUiAssetBundle::query()->find($id);
        if (!$bundle) {
            return $this->sendError('Bundle not found.', [], 404);
        }

        $activeFleets = ComfyUiGpuFleet::query()
            ->where('active_bundle_id', $bundle->id)
            ->orWhere('active_bundle_s3_prefix', $bundle->s3_prefix)
            ->get(['id', 'name', 'slug']);

        if ($activeFleets->isNotEmpty()) {
            $fleets = $activeFleets
                ->map(fn (ComfyUiGpuFleet $fleet) => [
                    'id' => $fleet->id,
                    'name' => $fleet->name,
                    'slug' => $fleet->slug,
                ])
                ->values()
                ->all();

            return $this->sendError('Bundle is active in one or more fleets and cannot be deleted.', [
                'fleets' => $fleets,
                'fleet_count' => count($fleets),
            ], 409);
        }

        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $bundlePrefix = (string) $bundle->s3_prefix;
        $bundleId = (string) $bundle->bundle_id;
        $user = $request->user();

        try {
            if ($bundlePrefix !== '') {
                Storage::disk($disk)->deleteDirectory($bundlePrefix);
            }
        } catch (\Throwable $e) {
            \Log::error('Bundle S3 delete failed', ['bundle_id' => $bundleId, 'error' => $e->getMessage()]);
            return $this->sendError('Bundle delete failed.', [], 500);
        }

        try {
            $bundle->delete();
        } catch (\Throwable $e) {
            \Log::error('Bundle DB delete failed', ['bundle_id' => $bundleId, 'error' => $e->getMessage()]);
            return $this->sendError('Bundle delete failed.', [], 500);
        }

        $audit->log(
            'bundle_deleted',
            null,
            null,
            null,
            ['bundle_id' => $bundleId, 's3_prefix' => $bundlePrefix],
            $user?->id,
            $user?->email
        );

        return $this->sendNoContent();
    }

    public function filesDestroy(Request $request, $id, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $asset = ComfyUiAssetFile::query()->find($id);
        if (!$asset) {
            return $this->sendError('Asset not found.', [], 404);
        }

        $bundleRefs = ComfyUiAssetBundleFile::query()
            ->where('asset_file_id', $asset->id)
            ->with(['bundle:id,bundle_id,name'])
            ->get();

        if ($bundleRefs->isNotEmpty()) {
            $bundles = $bundleRefs
                ->map(fn (ComfyUiAssetBundleFile $ref) => $ref->bundle)
                ->filter()
                ->unique('id')
                ->values()
                ->map(fn (ComfyUiAssetBundle $bundle) => [
                    'id' => $bundle->id,
                    'bundle_id' => $bundle->bundle_id,
                    'name' => $bundle->name,
                ])
                ->values()
                ->all();

            return $this->sendError(
                'Asset is referenced by one or more bundles and cannot be deleted.',
                [
                    'bundles' => $bundles,
                    'bundle_count' => count($bundles),
                ],
                409
            );
        }

        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $s3Key = (string) $asset->s3_key;
        $assetId = (int) $asset->id;
        $user = $request->user();

        try {
            if ($s3Key !== '') {
                Storage::disk($disk)->delete($s3Key);
            }
        } catch (\Throwable $e) {
            \Log::error('Asset S3 delete failed', ['asset_id' => $assetId, 'error' => $e->getMessage()]);
            return $this->sendError('Asset delete failed.', [], 500);
        }

        try {
            $asset->forceDelete();
        } catch (\Throwable $e) {
            \Log::error('Asset DB delete failed', ['asset_id' => $assetId, 'error' => $e->getMessage()]);
            return $this->sendError('Asset delete failed.', [], 500);
        }

        $audit->log(
            'asset_deleted',
            null,
            null,
            null,
            [
                'asset_id' => $assetId,
                'kind' => $asset->kind,
                'sha256' => $asset->sha256,
                's3_key' => $s3Key,
            ],
            $user?->id,
            $user?->email
        );

        return $this->sendNoContent();
    }

    public function bundlesStore(Request $request, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|required|max:255',
            'asset_file_ids' => 'array|required|min:1',
            'asset_file_ids.*' => 'integer|exists:comfyui_asset_files,id',
            'asset_overrides' => 'array|nullable',
            'asset_overrides.*.asset_file_id' => 'integer|required|exists:comfyui_asset_files,id',
            'asset_overrides.*.target_path' => 'string|nullable|max:1024',
            'asset_overrides.*.action' => 'string|nullable|in:copy,extract_zip,extract_tar_gz',
            'notes' => 'string|nullable|max:2000',
        ]);

        $validator->after(function ($validator) use ($request) {
            $overrides = (array) $request->input('asset_overrides', []);
            foreach ($overrides as $index => $override) {
                if (!is_array($override)) {
                    continue;
                }
                $targetPath = $override['target_path'] ?? null;
                if ($targetPath === null || trim((string) $targetPath) === '') {
                    continue;
                }
                if (!$this->isSafeBundleTargetPath((string) $targetPath)) {
                    $validator->errors()->add(
                        "asset_overrides.{$index}.target_path",
                        'Target path must be a relative path without ".." segments or backslashes.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $bundleId = (string) Str::uuid();
        $bundlePrefixBase = (string) config('services.comfyui.asset_bundle_prefix', 'bundles');
        $bundlePrefix = sprintf('%s/%s', $bundlePrefixBase, $bundleId);
        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $notes = $request->input('notes');
        $name = $request->input('name');
        $overrides = collect((array) $request->input('asset_overrides', []))
            ->keyBy('asset_file_id');

        $assetFiles = ComfyUiAssetFile::query()
            ->whereIn('id', $request->input('asset_file_ids'))
            ->get();

        if ($assetFiles->isEmpty() || $assetFiles->count() !== count($request->input('asset_file_ids'))) {
            return $this->sendError('Some asset files were not found.', [], 422);
        }

        $user = $request->user();

        try {
            $bundle = DB::transaction(function () use ($bundleId, $bundlePrefix, $notes, $name, $disk, $assetFiles, $user, $overrides) {
                $bundle = ComfyUiAssetBundle::query()->create([
                    'bundle_id' => $bundleId,
                    'name' => $name,
                    's3_prefix' => $bundlePrefix,
                    'notes' => $notes,
                    'created_by_user_id' => $user?->id,
                    'created_by_email' => $user?->email,
                ]);

                $manifestAssets = [];
                $position = 0;
                foreach ($assetFiles as $asset) {
                    $targetDir = self::ASSET_KIND_PATHS[$asset->kind] ?? self::ASSET_KIND_PATHS['other'];
                    $override = $overrides->get($asset->id);
                    $overrideTarget = is_array($override) ? ($override['target_path'] ?? null) : null;
                    $overrideAction = is_array($override) ? ($override['action'] ?? null) : null;
                    $targetPath = $overrideTarget ?: sprintf('%s/%s', $targetDir, $asset->original_filename);
                    $action = $overrideAction ?: 'copy';

                    ComfyUiAssetBundleFile::query()->create([
                        'bundle_id' => $bundle->id,
                        'asset_file_id' => $asset->id,
                        'target_path' => $targetPath,
                        'action' => $action,
                        'position' => $position++,
                    ]);

                    $manifestAssets[] = [
                        'asset_id' => $asset->id,
                        'kind' => $asset->kind,
                        'original_filename' => $asset->original_filename,
                        'size_bytes' => $asset->size_bytes,
                        'sha256' => $asset->sha256,
                        'asset_s3_key' => $asset->s3_key,
                        'target_path' => $targetPath,
                        'action' => $action,
                    ];
                }

                $manifest = [
                    'manifest_version' => 1,
                    'bundle_id' => $bundleId,
                    'name' => $name,
                    'created_at' => Carbon::now()->toIso8601String(),
                    'notes' => $notes,
                    'assets' => $manifestAssets,
                ];

                Storage::disk($disk)->put(sprintf('%s/manifest.json', $bundlePrefix), json_encode($manifest, JSON_PRETTY_PRINT));
                $bundle->manifest = $manifest;
                $bundle->save();

                return $bundle;
            });
        } catch (\Throwable $e) {
            \Log::error('Bundle creation failed', ['error' => $e->getMessage()]);
            return $this->sendError('Bundle creation failed.', [], 500);
        }

        $audit->log(
            'bundle_created',
            $bundle->id,
            null,
            $notes,
            ['bundle_id' => $bundleId],
            $user?->id,
            $user?->email
        );

        return $this->sendResponse($bundle, 'ComfyUI asset bundle created successfully', [], 201);
    }

    public function bundlesUpdate(Request $request, $id): JsonResponse
    {
        $bundle = ComfyUiAssetBundle::query()->find($id);
        if (!$bundle) {
            return $this->sendError('Bundle not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|nullable|max:255',
            'notes' => 'string|nullable|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        if ($request->has('name')) {
            $bundle->name = $request->input('name');
        }
        if ($request->has('notes')) {
            $bundle->notes = $request->input('notes');
        }
        $bundle->save();

        return $this->sendResponse($bundle, 'Bundle updated successfully');
    }

    public function bundleManifest($id, PresignedUrlService $presigned): JsonResponse
    {
        $bundle = ComfyUiAssetBundle::query()->find($id);
        if (!$bundle) {
            return $this->sendError('Bundle not found.', [], 404);
        }

        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);
        $manifestKey = sprintf('%s/manifest.json', $bundle->s3_prefix);

        $downloadUrl = $presigned->downloadUrl($disk, $manifestKey, $ttlSeconds);

        return $this->sendResponse([
            'bundle_id' => $bundle->bundle_id,
            'manifest_key' => $manifestKey,
            'download_url' => $downloadUrl,
            'expires_in' => $ttlSeconds,
        ], 'Bundle manifest ready');
    }

    public function auditLogsIndex(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $perPage = min((int) $request->get('perPage', 20), 200);
        $page = max((int) $request->get('page', 1), 1);
        $orderStr = $request->get('order', 'created_at:desc');

        $query = ComfyUiAssetAuditLog::query();

        if ($request->has('event')) {
            $events = is_array($request->get('event')) ? $request->get('event') : explode(',', $request->get('event'));
            $query->whereIn('event', $events);
        }

        if ($request->has('bundle_id')) {
            $query->where('bundle_id', (int) $request->get('bundle_id'));
        }

        if ($request->has('asset_file_id')) {
            $query->where('asset_file_id', (int) $request->get('asset_file_id'));
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->get('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->get('to_date'));
        }

        [$orderField, $orderDir] = $this->parseOrder($orderStr);
        $query->orderBy($orderField, $orderDir);

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $disk = (string) config('services.comfyui.logs_disk', 'comfyui_logs');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        $items = $items->map(function (ComfyUiAssetAuditLog $log) use ($presigned, $disk, $ttlSeconds) {
            $payload = $log->toArray();
            if (!empty($log->artifact_s3_key)) {
                $payload['artifact_download_url'] = $presigned->downloadUrl($disk, $log->artifact_s3_key, $ttlSeconds);
                $payload['artifact_expires_in'] = $ttlSeconds;
            }
            return $payload;
        });

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $total,
            'totalPages' => ceil($total / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => null,
            'filters' => [],
        ], 'Asset audit logs retrieved successfully');
    }

    public function auditLogsExport(Request $request)
    {
        $query = ComfyUiAssetAuditLog::query();

        if ($request->has('event')) {
            $events = is_array($request->get('event')) ? $request->get('event') : explode(',', $request->get('event'));
            $query->whereIn('event', $events);
        }

        if ($request->has('bundle_id')) {
            $query->where('bundle_id', (int) $request->get('bundle_id'));
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->get('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->get('to_date'));
        }

        $items = $query->orderBy('created_at', 'desc')->get();
        $format = strtolower((string) $request->get('format', 'ndjson'));

        if ($format === 'json') {
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'totalItems' => $items->count(),
                ],
                'message' => 'Asset audit logs export ready',
            ]);
        }

        $lines = $items->map(fn ($item) => json_encode($item->toArray()))->implode("\n");

        return response($lines, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Content-Disposition' => 'attachment; filename="comfyui-asset-audit-logs.ndjson"',
        ]);
    }

    private function hasUnsafePathChars(string $filename): bool
    {
        if ($filename === '') {
            return true;
        }
        if (Str::contains($filename, ['..', '/', '\\'])) {
            return true;
        }
        return basename($filename) !== $filename;
    }

    private function isSafeBundleTargetPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (Str::startsWith($path, '/')) {
            return false;
        }
        if (Str::contains($path, '\\')) {
            return false;
        }
        $parts = array_values(array_filter(explode('/', $path), static fn ($part) => $part !== ''));
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                return false;
            }
        }
        return !empty($parts);
    }

    private function parseOrder(string $orderStr): array
    {
        $parts = explode(':', $orderStr, 2);
        $field = $parts[0] ?? 'created_at';
        $dir = strtolower($parts[1] ?? 'desc');

        $allowedFields = ['id', 'created_at', 'event', 'bundle_id', 'asset_file_id'];
        if (!in_array($field, $allowedFields, true)) {
            $field = 'created_at';
        }

        return [$field, $dir === 'asc' ? 'asc' : 'desc'];
    }

}
