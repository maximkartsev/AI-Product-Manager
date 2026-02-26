<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Http\Resources\Effect as EffectResource;
use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\File;
use App\Models\User;
use App\Models\Video;
use App\Services\PresignedUrlService;
use App\Services\WorkflowPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator as Validator;
use Illuminate\Support\Str;

class EffectsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Effect::query()->with('category');

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $searchFields = array_merge(['name', 'slug', 'description', 'type'], $this->getRelationSearchFields(Effect::class));
        $this->addSearchCriteria($searchStr, $query, $searchFields);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Effect::class);

        $this->addFiltersCriteria($query, $filters, Effect::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => EffectResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Effects retrieved successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        return $this->sendResponse(new EffectResource($item), trans('Effect retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $item = new Effect();

        return $this->sendResponse(new EffectResource($item), null);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Effect::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        if (($input['publication_status'] ?? null) === 'published') {
            $workflowId = $input['workflow_id'] ?? null;
            if (!$workflowId) {
                return $this->sendError('Effect has no configured workflow.', [], 422);
            }
            $hasProductionFleet = ComfyUiWorkflowFleet::query()
                ->where('workflow_id', $workflowId)
                ->where('stage', 'production')
                ->exists();
            if (!$hasProductionFleet) {
                return $this->sendError('Effect is not available for production processing.', [], 422);
            }
        }

        try {
            $item = Effect::create($input);
        } catch (\Exception $e) {
                        \Log::error('Effect operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        return $this->sendResponse(new EffectResource($item), trans('Effect created successfully'), [], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        $input = $request->all();

        $rules = Effect::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        $nextPublicationStatus = $input['publication_status'] ?? $item->publication_status;
        if ($nextPublicationStatus === 'published') {
            $workflowId = $input['workflow_id'] ?? $item->workflow_id;
            if (!$workflowId) {
                return $this->sendError('Effect has no configured workflow.', [], 422);
            }
            $hasProductionFleet = ComfyUiWorkflowFleet::query()
                ->where('workflow_id', $workflowId)
                ->where('stage', 'production')
                ->exists();
            if (!$hasProductionFleet) {
                return $this->sendError('Effect is not available for production processing.', [], 422);
            }
        }

        $item->fill($input);

        try {
            $item->save();
        } catch (\Exception $e) {
                        \Log::error('Effect operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        $item->fresh();

        return $this->sendResponse(new EffectResource($item), trans('Effect updated successfully'));
    }

    public function stressTest(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'count' => 'integer|required|min:1|max:200',
            'input_file_id' => 'integer|required',
            'input_payload' => 'array|nullable',
            'execute_on_production_fleet' => 'boolean|nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $effect = Effect::query()->with('workflow')->find($id);
        if (!$effect) {
            return $this->sendError('Effect not found.', [], 404);
        }
        if (!$effect->workflow_id || !$effect->workflow) {
            return $this->sendError('Effect has no configured workflow.', [], 422);
        }

        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $inputFile = File::query()->find((int) $request->input('input_file_id'));
        if (!$inputFile) {
            return $this->sendError('File not found.', [], 404);
        }
        if ((int) $inputFile->user_id !== (int) $user->id) {
            return $this->sendError('File ownership mismatch.', [], 403);
        }

        $inputPayload = $request->input('input_payload');
        if (!is_array($inputPayload)) {
            $inputPayload = [];
        }

        try {
            [$jobPayload, $workUnits] = $this->preparePayloadAndUnits($effect, $inputPayload, $inputFile, $user);
        } catch (\RuntimeException $e) {
            if (!$this->shouldFallbackStressTestPayload($e)) {
                return $this->sendError($e->getMessage(), [], 422);
            }

            $jobPayload = $this->stressTestFallbackPayload($effect);
            $workUnits = ['units' => 1.0, 'kind' => 'job'];
        }

        $executeOnProduction = (bool) $request->input('execute_on_production_fleet', false);
        $stage = $this->determineStressTestStage($effect, $executeOnProduction);

        $hasFleetAssignment = ComfyUiWorkflowFleet::query()
            ->where('workflow_id', $effect->workflow_id)
            ->where('stage', $stage)
            ->exists();
        if (!$hasFleetAssignment) {
            return $this->sendError("Effect is not available for {$stage} processing.", [], 422);
        }

        $count = (int) $request->input('count');
        $provider = (string) config('services.comfyui.default_provider', 'self_hosted');
        $tenantId = (string) tenant()->getKey();
        $videoIds = [];
        $jobIds = [];

        for ($i = 0; $i < $count; $i++) {
            [$video, $job] = DB::connection('tenant')->transaction(function () use ($effect, $inputFile, $inputPayload, $user, $tenantId, $provider, $jobPayload) {
                $video = Video::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => (int) $user->id,
                    'effect_id' => $effect->id,
                    'original_file_id' => $inputFile->id,
                    'status' => 'queued',
                    'is_public' => false,
                    'input_payload' => $inputPayload,
                ]);

                $job = AiJob::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => (int) $user->id,
                    'effect_id' => $effect->id,
                    'provider' => $provider,
                    'video_id' => $video->id,
                    'input_file_id' => $inputFile->id,
                    'status' => 'queued',
                    'idempotency_key' => 'stress_test_' . (string) Str::uuid(),
                    'requested_tokens' => 0,
                    'reserved_tokens' => 0,
                    'consumed_tokens' => 0,
                    'input_payload' => $jobPayload,
                ]);

                return [$video, $job];
            });

            AiJobDispatch::query()->create([
                'tenant_id' => $tenantId,
                'tenant_job_id' => $job->id,
                'provider' => $provider,
                'workflow_id' => $effect->workflow_id,
                'stage' => $stage,
                'status' => 'queued',
                'priority' => 0,
                'attempts' => 0,
                'work_units' => $workUnits['units'] ?? null,
                'work_unit_kind' => $workUnits['kind'] ?? null,
            ]);

            $videoIds[] = $video->id;
            $jobIds[] = $job->id;
        }

        return $this->sendResponse([
            'queued_count' => count($jobIds),
            'video_ids' => $videoIds,
            'job_ids' => $jobIds,
        ], 'Stress test queued');
    }

    public function destroy($id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
                        \Log::error('Effect operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        return $this->sendNoContent();
    }

    public function createUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'string|required|in:workflow,thumbnail,preview_video',
            'mime_type' => 'string|required|max:255',
            'size' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $originalFilename = (string) $request->input('original_filename');
        if (!$this->isSafeFilename($originalFilename)) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $kind = (string) $request->input('kind');
        $mimeType = strtolower((string) $request->input('mime_type'));
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension === '') {
            $extension = match ($kind) {
                'workflow' => 'json',
                'thumbnail' => 'png',
                'preview_video' => 'mp4',
                default => 'bin',
            };
        }

        $path = match ($kind) {
            'workflow' => sprintf('resources/comfyui/workflows/admin/%s.%s', (string) Str::uuid(), $extension),
            'thumbnail' => sprintf('effects/thumbnails/%s.%s', (string) Str::uuid(), $extension),
            'preview_video' => sprintf('effects/previews/%s.%s', (string) Str::uuid(), $extension),
            default => sprintf('effects/unknown/%s.%s', (string) Str::uuid(), $extension),
        };

        $disk = $kind === 'workflow'
            ? (string) config('services.comfyui.workflow_disk', 's3')
            : (string) config('filesystems.default', 's3');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        try {
            $upload = $presigned->uploadUrl($disk, $path, $ttlSeconds, $mimeType);
        } catch (\Throwable $e) {
            return $this->sendError('Upload URL generation failed.', [], 500);
        }

        $data = [
            'path' => $path,
            'upload_url' => $upload['url'] ?? null,
            'upload_headers' => $upload['headers'] ?? [],
            'expires_in' => $ttlSeconds,
        ];

        if ($kind !== 'workflow') {
            $data['public_url'] = Storage::disk($disk)->url($path);
        }

        return $this->sendResponse($data, 'Upload initialized');
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

    private function determineStressTestStage(Effect $effect, bool $executeOnProduction): string
    {
        if ($effect->publication_status === 'published') {
            return 'production';
        }

        return $executeOnProduction ? 'production' : 'staging';
    }

    private function shouldFallbackStressTestPayload(\RuntimeException $exception): bool
    {
        return in_array($exception->getMessage(), [
            'Effect is not configured for processing.',
            'Workflow has no workflow JSON path configured.',
            'Workflow file not found.',
            'Workflow JSON is invalid or empty.',
        ], true);
    }

    private function stressTestFallbackPayload(Effect $effect): array
    {
        return [
            'workflow' => [],
            'assets' => [],
            'output_node_id' => $effect->workflow?->output_node_id,
            'output_extension' => $effect->workflow?->output_extension ?: 'mp4',
            'output_mime_type' => $effect->workflow?->output_mime_type ?: 'video/mp4',
        ];
    }

    /**
     * @return array{0: array, 1: array{units: float, kind: string}}
     */
    private function preparePayloadAndUnits(Effect $effect, array $inputPayload, File $inputFile, User $user): array
    {
        if (!$effect->workflow_id || !$effect->workflow) {
            throw new \RuntimeException('Effect is not configured for processing.');
        }

        $service = app(WorkflowPayloadService::class);
        $properties = $effect->workflow->properties ?? [];
        $allowed = $this->buildAllowedPropertyMap($properties);
        $userInput = $this->extractUserInput($inputPayload, $allowed, $user);
        $resolvedProps = $service->resolveProperties($effect->workflow, $effect, $userInput);
        $this->assertRequiredProperties($properties, $resolvedProps);
        $payload = $service->buildJobPayload($effect, $resolvedProps, $inputFile);
        $workUnits = $service->computeWorkUnitsFromResolvedProps($effect->workflow, $resolvedProps);

        return [$payload, $workUnits];
    }

    private function buildAllowedPropertyMap(array $properties): array
    {
        $allowed = [];
        foreach ($properties as $prop) {
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
            $allowed[$key] = $prop;
        }
        return $allowed;
    }

    private function extractUserInput(array $inputPayload, array $allowed, User $user): array
    {
        $userInput = [];
        foreach ($inputPayload as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                throw new \RuntimeException("Unsupported property: {$key}");
            }
            $prop = $allowed[$key];
            $type = $prop['type'] ?? 'text';
            if ($type === 'text') {
                $normalized = $this->normalizeTextInput($value);
                if ($normalized !== null) {
                    $userInput[$key] = $normalized;
                }
                continue;
            }
            if (in_array($type, ['image', 'video'], true)) {
                $fileId = $this->normalizeFileId($value);
                if (!$fileId) {
                    throw new \RuntimeException("Invalid file id for {$key}.");
                }
                $file = File::query()->find($fileId);
                if (!$file) {
                    throw new \RuntimeException("File not found for {$key}.");
                }
                if ((int) $file->user_id !== (int) $user->id) {
                    throw new \RuntimeException("File ownership mismatch for {$key}.");
                }
                $expiresAt = data_get($file->metadata, 'expires_at');
                if ($expiresAt && now()->gte(\Carbon\Carbon::parse($expiresAt))) {
                    throw new \RuntimeException("File has expired for {$key}.");
                }
                if ($expiresAt) {
                    $metadata = $file->metadata ?? [];
                    unset($metadata['expires_at']);
                    $file->metadata = $metadata;
                    $file->save();
                }
                if (!$this->matchesFileType($file->mime_type, $type)) {
                    throw new \RuntimeException("File type mismatch for {$key}.");
                }
                $userInput[$key] = [
                    'disk' => $file->disk,
                    'path' => $file->path,
                ];
                continue;
            }

            $normalized = $this->normalizeTextInput($value);
            if ($normalized !== null) {
                $userInput[$key] = $normalized;
            }
        }

        return $userInput;
    }

    private function assertRequiredProperties(array $properties, array $resolvedProps): void
    {
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            if (empty($prop['required']) || !empty($prop['is_primary_input'])) {
                continue;
            }
            $key = $prop['key'] ?? null;
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $type = $prop['type'] ?? 'text';
            $value = $resolvedProps[$key] ?? null;
            if ($type === 'text') {
                $text = is_string($value) ? trim($value) : '';
                if ($text === '') {
                    throw new \RuntimeException("Missing required property: {$key}");
                }
                continue;
            }
            if (in_array($type, ['image', 'video'], true)) {
                if (is_array($value)) {
                    $path = $value['path'] ?? null;
                    if (is_string($path) && trim($path) !== '') {
                        continue;
                    }
                } elseif (is_string($value) && trim($value) !== '') {
                    continue;
                }
                throw new \RuntimeException("Missing required property: {$key}");
            }
            if ($value === null || $value === '') {
                throw new \RuntimeException("Missing required property: {$key}");
            }
        }
    }

    private function normalizeTextInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeFileId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }
        if (is_numeric($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }
        return null;
    }

    private function matchesFileType(?string $mimeType, string $expectedType): bool
    {
        if (!$mimeType) {
            return false;
        }
        $normalized = strtolower($mimeType);
        return $expectedType === 'image'
            ? str_starts_with($normalized, 'image/')
            : str_starts_with($normalized, 'video/');
    }
}
