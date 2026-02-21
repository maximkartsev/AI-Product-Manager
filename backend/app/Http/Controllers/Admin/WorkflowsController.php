<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Http\Resources\Workflow as WorkflowResource;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Workflow;
use App\Services\PresignedUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WorkflowsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Workflow::query()->with('fleets');

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $searchFields = ['name', 'slug', 'description'];
        $this->addSearchCriteria($searchStr, $query, $searchFields);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Workflow::class);
        $this->addFiltersCriteria($query, $filters, Workflow::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        return $this->sendResponse([
            'items' => WorkflowResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ], 'Workflows retrieved successfully');
    }

    public function show($id): JsonResponse
    {
        $item = Workflow::with('fleets')->find($id);
        if (is_null($item)) {
            return $this->sendError('Workflow not found');
        }

        return $this->sendResponse(new WorkflowResource($item), 'Workflow retrieved successfully');
    }

    public function create(Request $request): JsonResponse
    {
        $item = new Workflow();
        return $this->sendResponse(new WorkflowResource($item), null);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();
        $validator = Validator::make($input, Workflow::getRules());

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        try {
            $item = Workflow::create($input);
        } catch (\Exception $e) {
            \Log::error('Workflow operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        return $this->sendResponse(new WorkflowResource($item), 'Workflow created successfully', [], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Workflow::find($id);
        if (is_null($item)) {
            return $this->sendError('Workflow not found');
        }

        $input = $request->all();
        if (array_key_exists('slug', $input) && $input['slug'] !== $item->slug) {
            return $this->sendError('Validation Error', [
                'slug' => ['Slug cannot be changed after creation.'],
            ], 422);
        }
        $rules = Workflow::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $item->fill($input);

        try {
            $item->save();
        } catch (\Exception $e) {
            \Log::error('Workflow operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        $item->fresh();

        return $this->sendResponse(new WorkflowResource($item), 'Workflow updated successfully');
    }

    public function assignFleets(Request $request, $id): JsonResponse
    {
        $workflow = Workflow::find($id);
        if (is_null($workflow)) {
            return $this->sendError('Workflow not found');
        }

        $validator = Validator::make($request->all(), [
            'staging_fleet_id' => 'integer|nullable|exists:comfyui_gpu_fleets,id',
            'production_fleet_id' => 'integer|nullable|exists:comfyui_gpu_fleets,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $stagingFleetId = $request->input('staging_fleet_id');
        $productionFleetId = $request->input('production_fleet_id');
        $fleetIds = array_values(array_filter([$stagingFleetId, $productionFleetId], fn ($id) => !is_null($id)));

        $fleetsById = ComfyUiGpuFleet::query()
            ->whereIn('id', $fleetIds)
            ->get()
            ->keyBy('id');

        if ($stagingFleetId !== null && (!isset($fleetsById[$stagingFleetId]) || $fleetsById[$stagingFleetId]->stage !== 'staging')) {
            return $this->sendError('Validation Error', [
                'staging_fleet_id' => ['Selected fleet must belong to staging stage.'],
            ], 422);
        }

        if ($productionFleetId !== null && (!isset($fleetsById[$productionFleetId]) || $fleetsById[$productionFleetId]->stage !== 'production')) {
            return $this->sendError('Validation Error', [
                'production_fleet_id' => ['Selected fleet must belong to production stage.'],
            ], 422);
        }

        $user = $request->user();
        $now = Carbon::now();

        DB::transaction(function () use ($workflow, $stagingFleetId, $productionFleetId, $user, $now) {
            if ($stagingFleetId) {
                ComfyUiWorkflowFleet::query()->updateOrCreate(
                    ['workflow_id' => $workflow->id, 'stage' => 'staging'],
                    [
                        'fleet_id' => $stagingFleetId,
                        'assigned_at' => $now,
                        'assigned_by_user_id' => $user?->id,
                        'assigned_by_email' => $user?->email,
                    ]
                );
            } else {
                ComfyUiWorkflowFleet::query()
                    ->where('workflow_id', $workflow->id)
                    ->where('stage', 'staging')
                    ->delete();
            }

            if ($productionFleetId) {
                ComfyUiWorkflowFleet::query()->updateOrCreate(
                    ['workflow_id' => $workflow->id, 'stage' => 'production'],
                    [
                        'fleet_id' => $productionFleetId,
                        'assigned_at' => $now,
                        'assigned_by_user_id' => $user?->id,
                        'assigned_by_email' => $user?->email,
                    ]
                );
            } else {
                ComfyUiWorkflowFleet::query()
                    ->where('workflow_id', $workflow->id)
                    ->where('stage', 'production')
                    ->delete();
            }
        });

        $workflow->load('fleets');

        return $this->sendResponse(new WorkflowResource($workflow), 'Workflow fleet assignments updated successfully');
    }

    public function destroy($id): JsonResponse
    {
        $item = Workflow::find($id);
        if (is_null($item)) {
            return $this->sendError('Workflow not found');
        }

        $effects = $item->effects()->select('id', 'name', 'slug')->get();
        if ($effects->isNotEmpty()) {
            return $this->sendError(
                'Cannot delete workflow: it is used by ' . $effects->count() . ' effect(s).',
                ['effects' => $effects->toArray()],
                409
            );
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            \Log::error('Workflow operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        return $this->sendNoContent();
    }

    public function createUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'string|required|in:workflow_json,property_asset',
            'workflow_id' => 'integer|nullable',
            'property_key' => 'string|nullable|max:255',
            'mime_type' => 'string|required|max:255',
            'size' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $originalFilename = (string) $request->input('original_filename');
        if ($this->hasUnsafePathChars($originalFilename)) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $kind = (string) $request->input('kind');
        $mimeType = strtolower((string) $request->input('mime_type'));
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $workflowId = $request->input('workflow_id', 'new');
        $propertyKey = $request->input('property_key', 'asset');

        if ($extension === '') {
            $extension = $kind === 'workflow_json' ? 'json' : 'bin';
        }

        $path = match ($kind) {
            'workflow_json' => sprintf('workflows/%s/workflow.json', $workflowId),
            'property_asset' => sprintf(
                'workflows/%s/assets/%s/%s.%s',
                $workflowId,
                $propertyKey,
                (string) Str::uuid(),
                $extension
            ),
            default => sprintf('workflows/unknown/%s.%s', (string) Str::uuid(), $extension),
        };

        $disk = (string) config('services.comfyui.workflow_disk', 's3');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        try {
            $upload = $presigned->uploadUrl($disk, $path, $ttlSeconds, $mimeType);
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
}
