<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ComfyUiAssetBundle;
use App\Models\ComfyUiAssetBundleFile;
use App\Models\ComfyUiAssetFile;
use App\Models\ComfyUiAssetAuditLog;
use App\Models\ComfyUiWorkflowActiveBundle;
use App\Models\Workflow;
use App\Services\ComfyUiAssetAuditService;
use App\Services\PresignedUrlService;
use Aws\Ssm\SsmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ComfyUiAssetsController extends BaseController
{
    private const ASSET_KIND_PATHS = [
        'checkpoint' => 'models/checkpoints',
        'lora' => 'models/loras',
        'vae' => 'models/vae',
        'embedding' => 'models/embeddings',
        'controlnet' => 'models/controlnet',
        'custom_node' => 'custom_nodes',
        'other' => 'models/other',
    ];

    public function filesIndex(Request $request): JsonResponse
    {
        $query = ComfyUiAssetFile::query()->with('workflow:id,slug,name');

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);
        $query->select($fieldsToSelect);

        $searchFields = ['original_filename', 's3_key', 'kind'];
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
            'workflow_id' => 'integer|required|exists:workflows,id',
            'kind' => 'string|required|in:' . implode(',', array_keys(self::ASSET_KIND_PATHS)),
            'mime_type' => 'string|required|max:255',
            'size_bytes' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
            'sha256' => 'string|nullable|max:128',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $originalFilename = (string) $request->input('original_filename');
        if ($this->hasUnsafePathChars($originalFilename)) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $workflow = Workflow::query()->find((int) $request->input('workflow_id'));
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $uploadPrefix = (string) config('services.comfyui.asset_upload_prefix', 'uploads');
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'bin';
        }

        $uuid = (string) Str::uuid();
        $path = sprintf('%s/%s/%s/%s.%s', $uploadPrefix, $workflow->slug, $uuid, $uuid, $extension);
        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

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
        ], 'Upload initialized');
    }

    public function filesStore(Request $request, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'workflow_id' => 'integer|required|exists:workflows,id',
            'kind' => 'string|required|in:' . implode(',', array_keys(self::ASSET_KIND_PATHS)),
            'original_filename' => 'string|required|max:512',
            's3_key' => 'string|required|max:2048',
            'content_type' => 'string|nullable|max:255',
            'size_bytes' => 'integer|nullable|min:1',
            'sha256' => 'string|nullable|max:128',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        if ($this->hasUnsafePathChars((string) $request->input('original_filename'))) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $workflow = Workflow::query()->find((int) $request->input('workflow_id'));
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $asset = ComfyUiAssetFile::query()->create([
            'workflow_id' => $workflow->id,
            'kind' => $request->input('kind'),
            'original_filename' => $request->input('original_filename'),
            's3_key' => $request->input('s3_key'),
            'content_type' => $request->input('content_type'),
            'size_bytes' => $request->input('size_bytes'),
            'sha256' => $request->input('sha256'),
            'uploaded_at' => Carbon::now(),
        ]);

        $user = $request->user();
        $audit->log(
            'asset_uploaded',
            null,
            $asset->id,
            null,
            ['workflow_slug' => $workflow->slug, 'kind' => $asset->kind],
            $user?->id,
            $user?->email
        );

        return $this->sendResponse($asset, 'ComfyUI asset file created successfully', [], 201);
    }

    public function bundlesIndex(Request $request): JsonResponse
    {
        $query = ComfyUiAssetBundle::query()->with('workflow:id,slug,name');

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);
        $query->select($fieldsToSelect);

        $searchFields = ['bundle_id', 'notes'];
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

    public function activeBundlesIndex(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('perPage', 50), 200);
        $page = max((int) $request->get('page', 1), 1);
        $orderStr = $request->get('order', 'activated_at:desc');

        $query = ComfyUiWorkflowActiveBundle::query()
            ->with([
                'workflow:id,slug,name',
                'bundle:id,bundle_id,s3_prefix,workflow_id',
            ]);

        if ($request->has('workflow_id')) {
            $query->where('workflow_id', (int) $request->get('workflow_id'));
        }
        if ($request->has('stage')) {
            $query->where('stage', (string) $request->get('stage'));
        }
        if ($request->has('bundle_id')) {
            $query->where('bundle_id', (int) $request->get('bundle_id'));
        }

        $parts = explode(':', $orderStr, 2);
        $orderField = $parts[0] ?? 'activated_at';
        $orderDir = strtolower($parts[1] ?? 'desc');
        $allowedFields = ['id', 'activated_at', 'stage', 'workflow_id', 'bundle_id', 'created_at'];
        if (!in_array($orderField, $allowedFields, true)) {
            $orderField = 'activated_at';
        }
        $query->orderBy($orderField, $orderDir === 'asc' ? 'asc' : 'desc');

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $total,
            'totalPages' => ceil($total / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
        ], 'Active asset bundles retrieved successfully');
    }

    public function cleanupCandidates(Request $request): JsonResponse
    {
        $activeBundleIds = ComfyUiWorkflowActiveBundle::query()->pluck('bundle_id')->unique();

        $query = ComfyUiAssetBundle::query()
            ->with(['workflow' => fn ($q) => $q->withTrashed()->select('id', 'slug', 'name', 'deleted_at')]);

        if ($activeBundleIds->isNotEmpty()) {
            $query->whereNotIn('id', $activeBundleIds);
        }

        $bundles = $query->orderBy('id', 'desc')->get();
        $items = $bundles->map(function (ComfyUiAssetBundle $bundle) {
            $workflow = $bundle->workflow;
            $reason = ($workflow && method_exists($workflow, 'trashed') && $workflow->trashed())
                ? 'workflow_deleted'
                : 'never_activated';

            return [
                'id' => $bundle->id,
                'bundle_id' => $bundle->bundle_id,
                's3_prefix' => $bundle->s3_prefix,
                'reason' => $reason,
                'workflow' => $workflow ? [
                    'id' => $workflow->id,
                    'slug' => $workflow->slug,
                    'name' => $workflow->name,
                    'deleted_at' => $workflow->deleted_at,
                ] : null,
            ];
        });

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $items->count(),
        ], 'Cleanup candidates retrieved successfully');
    }

    public function bundlesStore(Request $request, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'workflow_id' => 'integer|required|exists:workflows,id',
            'asset_file_ids' => 'array|required|min:1',
            'asset_file_ids.*' => 'integer|exists:comfyui_asset_files,id',
            'notes' => 'string|nullable|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $workflow = Workflow::query()->find((int) $request->input('workflow_id'));
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $bundleId = (string) Str::uuid();
        $bundlePrefixBase = (string) config('services.comfyui.asset_bundle_prefix', 'bundles');
        $bundlePrefix = sprintf('%s/%s/%s', $bundlePrefixBase, $workflow->slug, $bundleId);
        $disk = (string) config('services.comfyui.models_disk', 'comfyui_models');
        $notes = $request->input('notes');

        $assetFiles = ComfyUiAssetFile::query()
            ->whereIn('id', $request->input('asset_file_ids'))
            ->where('workflow_id', $workflow->id)
            ->get();

        if ($assetFiles->isEmpty() || $assetFiles->count() !== count($request->input('asset_file_ids'))) {
            return $this->sendError('Some asset files were not found or belong to a different workflow.', [], 422);
        }

        $user = $request->user();

        try {
            $bundle = DB::transaction(function () use ($workflow, $bundleId, $bundlePrefix, $notes, $disk, $assetFiles, $user) {
                $bundle = ComfyUiAssetBundle::query()->create([
                    'workflow_id' => $workflow->id,
                    'bundle_id' => $bundleId,
                    's3_prefix' => $bundlePrefix,
                    'notes' => $notes,
                    'created_by_user_id' => $user?->id,
                    'created_by_email' => $user?->email,
                ]);

                $manifestAssets = [];
                $position = 0;
                foreach ($assetFiles as $asset) {
                    $targetDir = self::ASSET_KIND_PATHS[$asset->kind] ?? self::ASSET_KIND_PATHS['other'];
                    $targetPath = sprintf('%s/%s', $targetDir, $asset->original_filename);
                    $bundleKey = sprintf('%s/%s', $bundlePrefix, $targetPath);

                    $copied = Storage::disk($disk)->copy($asset->s3_key, $bundleKey);
                    if (!$copied) {
                        throw new \RuntimeException("Failed to copy asset {$asset->id} into bundle.");
                    }

                    ComfyUiAssetBundleFile::query()->create([
                        'bundle_id' => $bundle->id,
                        'asset_file_id' => $asset->id,
                        'target_path' => $targetPath,
                        'position' => $position++,
                    ]);

                    $manifestAssets[] = [
                        'kind' => $asset->kind,
                        'original_filename' => $asset->original_filename,
                        'size_bytes' => $asset->size_bytes,
                        'sha256' => $asset->sha256,
                        's3_key' => $bundleKey,
                        'target_path' => $targetPath,
                    ];
                }

                $manifest = [
                    'bundle_id' => $bundleId,
                    'workflow_slug' => $workflow->slug,
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
            ['workflow_slug' => $workflow->slug, 'bundle_id' => $bundleId],
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
            'notes' => 'string|nullable|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $bundle->notes = $request->input('notes');
        $bundle->save();

        return $this->sendResponse($bundle, 'Bundle updated successfully');
    }

    public function bundlesActivate(Request $request, $id, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $bundle = ComfyUiAssetBundle::query()->with('workflow')->find($id);
        if (!$bundle || !$bundle->workflow) {
            return $this->sendError('Bundle not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'stage' => 'string|required|in:staging,production',
            'notes' => 'string|nullable|max:2000',
            'target_workflow_id' => 'integer|nullable|exists:workflows,id',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $stage = $request->input('stage');
        $now = Carbon::now();
        $targetWorkflowId = $request->input('target_workflow_id');
        $targetWorkflow = $bundle->workflow;
        if ($targetWorkflowId && (int) $targetWorkflowId !== $bundle->workflow_id) {
            $targetWorkflow = Workflow::query()->find((int) $targetWorkflowId);
            if (!$targetWorkflow) {
                return $this->sendError('Target workflow not found.', [], 404);
            }
        }

        // Record activation timestamp on the bundle itself (informational only)
        if ($stage === 'staging') {
            $bundle->active_staging_at = $now;
        } else {
            $bundle->active_production_at = $now;
        }
        $bundle->save();

        // Update SSM active bundle pointer
        $this->putActiveBundleParameter($stage, $targetWorkflow->slug, $bundle->s3_prefix);

        // Upsert active bundle mapping for workflow + stage
        ComfyUiWorkflowActiveBundle::query()->updateOrCreate(
            [
                'workflow_id' => $targetWorkflow->id,
                'stage' => $stage,
            ],
            [
                'bundle_id' => $bundle->id,
                'bundle_s3_prefix' => $bundle->s3_prefix,
                'activated_at' => $now,
                'activated_by_user_id' => $request->user()?->id,
                'activated_by_email' => $request->user()?->email,
                'notes' => $request->input('notes'),
            ]
        );

        $user = $request->user();
        $audit->log(
            'bundle_activated',
            $bundle->id,
            null,
            $request->input('notes'),
            ['workflow_slug' => $targetWorkflow->slug, 'stage' => $stage],
            $user?->id,
            $user?->email
        );

        return $this->sendResponse($bundle, 'Bundle activated successfully');
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

    private function putActiveBundleParameter(string $stage, string $workflowSlug, string $bundlePrefix): void
    {
        $region = (string) config('services.comfyui.aws_region', 'us-east-1');
        $client = new SsmClient([
            'version' => 'latest',
            'region' => $region,
        ]);

        $client->putParameter([
            'Name' => "/bp/{$stage}/assets/{$workflowSlug}/active_bundle",
            'Value' => $bundlePrefix,
            'Type' => 'String',
            'Overwrite' => true,
        ]);
    }
}
