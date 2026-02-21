<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ComfyUiAssetBundle;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Workflow;
use App\Services\ComfyUiAssetAuditService;
use App\Services\ComfyUiFleetSsmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ComfyUiFleetsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = ComfyUiGpuFleet::query()->with([
            'activeBundle:id,bundle_id,name,s3_prefix',
            'workflows:id,slug,name',
        ]);

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);
        $query->select($fieldsToSelect);

        $searchFields = ['slug', 'name', 'stage'];
        $this->addSearchCriteria($searchStr, $query, $searchFields);

        $orderStr = $request->get('order', 'id:desc');
        $filters = $this->extractFilters($request, ComfyUiGpuFleet::class);
        $this->addFiltersCriteria($query, $filters, ComfyUiGpuFleet::class);

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
        ], 'GPU fleets retrieved successfully');
    }

    public function show($id): JsonResponse
    {
        $fleet = ComfyUiGpuFleet::query()
            ->with(['activeBundle:id,bundle_id,name,s3_prefix', 'workflows:id,slug,name'])
            ->find($id);
        if (!$fleet) {
            return $this->sendError('Fleet not found', [], 404);
        }

        return $this->sendResponse($fleet, 'Fleet retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stage' => 'string|required|in:staging,production',
            'slug' => 'string|required|max:128',
            'name' => 'string|required|max:255',
            'instance_types' => 'array|nullable',
            'instance_types.*' => 'string|max:64',
            'max_size' => 'integer|required|min:0',
            'warmup_seconds' => 'integer|nullable|min:0',
            'backlog_target' => 'integer|nullable|min:0',
            'scale_to_zero_minutes' => 'integer|nullable|min:0',
            'ami_ssm_parameter' => 'string|nullable|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $stage = $request->input('stage');
        $slug = $request->input('slug');
        $amiParam = $request->input('ami_ssm_parameter')
            ?: "/bp/ami/fleets/{$stage}/{$slug}";

        $fleet = ComfyUiGpuFleet::query()->create([
            'stage' => $stage,
            'slug' => $slug,
            'name' => $request->input('name'),
            'instance_types' => $request->input('instance_types'),
            'max_size' => $request->input('max_size'),
            'warmup_seconds' => $request->input('warmup_seconds'),
            'backlog_target' => $request->input('backlog_target'),
            'scale_to_zero_minutes' => $request->input('scale_to_zero_minutes'),
            'ami_ssm_parameter' => $amiParam,
        ]);

        return $this->sendResponse($fleet, 'Fleet created successfully', [], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $fleet = ComfyUiGpuFleet::query()->find($id);
        if (!$fleet) {
            return $this->sendError('Fleet not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|nullable|max:255',
            'instance_types' => 'array|nullable',
            'instance_types.*' => 'string|max:64',
            'max_size' => 'integer|nullable|min:0',
            'warmup_seconds' => 'integer|nullable|min:0',
            'backlog_target' => 'integer|nullable|min:0',
            'scale_to_zero_minutes' => 'integer|nullable|min:0',
            'ami_ssm_parameter' => 'string|nullable|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        foreach (['name', 'instance_types', 'max_size', 'warmup_seconds', 'backlog_target', 'scale_to_zero_minutes', 'ami_ssm_parameter'] as $field) {
            if ($request->has($field)) {
                $fleet->{$field} = $request->input($field);
            }
        }

        $fleet->save();

        return $this->sendResponse($fleet, 'Fleet updated successfully');
    }

    public function assignWorkflows(Request $request, $id): JsonResponse
    {
        $fleet = ComfyUiGpuFleet::query()->find($id);
        if (!$fleet) {
            return $this->sendError('Fleet not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'workflow_ids' => 'present|array',
            'workflow_ids.*' => 'integer|exists:workflows,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $workflowIds = $request->input('workflow_ids', []);
        $stage = $fleet->stage;
        $user = $request->user();

        DB::transaction(function () use ($workflowIds, $stage, $fleet, $user) {
            if (!empty($workflowIds)) {
                ComfyUiWorkflowFleet::query()
                    ->where('stage', $stage)
                    ->whereIn('workflow_id', $workflowIds)
                    ->delete();
            }

            ComfyUiWorkflowFleet::query()
                ->where('fleet_id', $fleet->id)
                ->where('stage', $stage)
                ->delete();

            foreach ($workflowIds as $workflowId) {
                ComfyUiWorkflowFleet::query()->create([
                    'workflow_id' => $workflowId,
                    'fleet_id' => $fleet->id,
                    'stage' => $stage,
                    'assigned_at' => Carbon::now(),
                    'assigned_by_user_id' => $user?->id,
                    'assigned_by_email' => $user?->email,
                ]);
            }
        });

        $fleet->load('workflows');

        return $this->sendResponse($fleet, 'Workflows assigned to fleet');
    }

    public function activateBundle(Request $request, $id, ComfyUiAssetAuditService $audit, ComfyUiFleetSsmService $ssm): JsonResponse
    {
        $fleet = ComfyUiGpuFleet::query()->find($id);
        if (!$fleet) {
            return $this->sendError('Fleet not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'bundle_id' => 'required|integer|exists:comfyui_asset_bundles,id',
            'notes' => 'string|nullable|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $bundle = ComfyUiAssetBundle::query()->find($request->input('bundle_id'));
        if (!$bundle) {
            return $this->sendError('Bundle not found.', [], 404);
        }

        $fleet->active_bundle_id = $bundle->id;
        $fleet->active_bundle_s3_prefix = $bundle->s3_prefix;
        $fleet->save();

        $ssm->putActiveBundle($fleet->stage, $fleet->slug, $bundle->s3_prefix);

        $user = $request->user();
        $audit->log(
            'fleet_bundle_activated',
            $bundle->id,
            null,
            $request->input('notes'),
            ['fleet_slug' => $fleet->slug, 'stage' => $fleet->stage],
            $user?->id,
            $user?->email
        );

        return $this->sendResponse($fleet, 'Fleet bundle activated');
    }
}
