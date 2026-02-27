<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ComfyUiAssetBundle;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Workflow;
use App\Services\ComfyUiAssetAuditService;
use App\Services\ComfyUiFleetSsmService;
use App\Services\ComfyUiFleetTemplateService;
use InvalidArgumentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
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
        $items = $this->attachFleetMetrics($items);

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

    public function templates(ComfyUiFleetTemplateService $templates): JsonResponse
    {
        return $this->sendResponse([
            'items' => $templates->all(),
        ], 'Fleet templates retrieved successfully');
    }

    public function show($id): JsonResponse
    {
        $fleet = ComfyUiGpuFleet::query()
            ->with(['activeBundle:id,bundle_id,name,s3_prefix', 'workflows:id,slug,name'])
            ->find($id);
        if (!$fleet) {
            return $this->sendError('Fleet not found', [], 404);
        }

        $fleet = $this->attachFleetMetrics(collect([$fleet]))->first();

        return $this->sendResponse($fleet, 'Fleet retrieved successfully');
    }

    public function store(Request $request, ComfyUiFleetTemplateService $templates, ComfyUiFleetSsmService $ssm): JsonResponse
    {
        $forbiddenFields = [
            'instance_types',
            'max_size',
            'warmup_seconds',
            'backlog_target',
            'scale_to_zero_minutes',
            'ami_ssm_parameter',
        ];
        $forbiddenTouched = array_filter($forbiddenFields, fn ($field) => $request->has($field));
        if (!empty($forbiddenTouched)) {
            $errors = [];
            foreach ($forbiddenTouched as $field) {
                $errors[$field] = ['This field cannot be set via the API.'];
            }
            return $this->sendError('Validation Error', $errors, 422);
        }

        $validator = Validator::make($request->all(), [
            'stage' => 'string|required|in:staging,production',
            'slug' => 'string|required|max:128',
            'name' => 'string|required|max:255',
            'template_slug' => 'string|required|max:128',
            'instance_type' => 'string|required|max:64',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $stage = $request->input('stage');
        $slug = $request->input('slug');
        $templateSlug = $request->input('template_slug');
        $instanceType = $request->input('instance_type');

        $existingFleet = ComfyUiGpuFleet::query()
            ->where('stage', $stage)
            ->where('slug', $slug)
            ->first();
        if ($existingFleet) {
            return $this->sendError('Validation Error', [
                'slug' => ['A fleet with this slug already exists for this stage.'],
            ], 409);
        }

        try {
            $template = $templates->requireTemplate($templateSlug);
        } catch (InvalidArgumentException $e) {
            return $this->sendError('Validation Error', ['template_slug' => [$e->getMessage()]], 422);
        }

        if (!in_array($instanceType, $template['allowed_instance_types'], true)) {
            return $this->sendError('Validation Error', [
                'instance_type' => ['Instance type is not allowed for this template.'],
            ], 422);
        }

        $amiParam = "/bp/ami/fleets/{$stage}/{$slug}";

        // Write desired config first so a failure doesn't leave a partially-created fleet row.
        try {
            $ssm->putDesiredFleetConfig($stage, $slug, [
                'version' => 1,
                'template_slug' => $templateSlug,
                'instance_type' => $instanceType,
                'ami_ssm_parameter' => $amiParam,
                'enabled' => true,
            ]);
        } catch (\Throwable $e) {
            return $this->sendError('Failed to write desired fleet config to SSM', [
                'ssm' => [
                    $e->getMessage(),
                    'Hint: ensure the backend ECS task role allows ssm:PutParameter for /bp/fleets/*/*/desired_config (redeploy compute stack).',
                ],
            ], 502);
        }

        try {
            $fleet = ComfyUiGpuFleet::query()->create([
                'stage' => $stage,
                'slug' => $slug,
                'template_slug' => $templateSlug,
                'name' => $request->input('name'),
                'instance_types' => [$instanceType],
                'max_size' => $template['max_size'],
                'warmup_seconds' => $template['warmup_seconds'],
                'backlog_target' => $template['backlog_target'],
                'scale_to_zero_minutes' => $template['scale_to_zero_minutes'],
                'ami_ssm_parameter' => $amiParam,
            ]);
        } catch (QueryException $e) {
            // Handle duplicate key (stage+slug unique)
            $duplicate = isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
            if ($duplicate) {
                return $this->sendError('Validation Error', [
                    'slug' => ['A fleet with this slug already exists for this stage.'],
                ], 409);
            }

            return $this->sendError('Failed to create fleet', [
                'db' => ['Database error while creating fleet.'],
            ], 500);
        }

        return $this->sendResponse($fleet, 'Fleet created successfully', [], 201);
    }

    public function update(Request $request, $id, ComfyUiFleetTemplateService $templates, ComfyUiFleetSsmService $ssm): JsonResponse
    {
        $fleet = ComfyUiGpuFleet::query()->find($id);
        if (!$fleet) {
            return $this->sendError('Fleet not found', [], 404);
        }

        $forbiddenFields = [
            'instance_types',
            'max_size',
            'warmup_seconds',
            'backlog_target',
            'scale_to_zero_minutes',
            'ami_ssm_parameter',
        ];
        $forbiddenTouched = array_filter($forbiddenFields, fn ($field) => $request->has($field));
        if (!empty($forbiddenTouched)) {
            $errors = [];
            foreach ($forbiddenTouched as $field) {
                $errors[$field] = ['This field cannot be updated via the API.'];
            }
            return $this->sendError('Validation Error', $errors, 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|nullable|max:255',
            'template_slug' => 'string|nullable|max:128',
            'instance_type' => 'string|nullable|max:64',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        if ($request->has('name')) {
            $fleet->name = $request->input('name');
        }

        $shouldUpdateDesiredConfig = $request->has('template_slug') || $request->has('instance_type');
        if ($shouldUpdateDesiredConfig) {
            $nextTemplateSlug = $request->input('template_slug', $fleet->template_slug);
            $nextInstanceType = $request->input('instance_type', ($fleet->instance_types[0] ?? null));

            if (!$nextInstanceType) {
                return $this->sendError('Validation Error', [
                    'instance_type' => ['Instance type is required.'],
                ], 422);
            }

            try {
                $template = $templates->requireTemplate($nextTemplateSlug);
            } catch (InvalidArgumentException $e) {
                return $this->sendError('Validation Error', ['template_slug' => [$e->getMessage()]], 422);
            }

            if (!in_array($nextInstanceType, $template['allowed_instance_types'], true)) {
                return $this->sendError('Validation Error', [
                    'instance_type' => ['Instance type is not allowed for this template.'],
                ], 422);
            }

            try {
                $ssm->putDesiredFleetConfig($fleet->stage, $fleet->slug, [
                    'version' => 1,
                    'template_slug' => $nextTemplateSlug,
                    'instance_type' => $nextInstanceType,
                    'ami_ssm_parameter' => $fleet->ami_ssm_parameter,
                    'enabled' => true,
                ]);
            } catch (\Throwable $e) {
                return $this->sendError('Failed to write desired fleet config to SSM', [
                    'ssm' => [
                        $e->getMessage(),
                        'Hint: ensure the backend ECS task role allows ssm:PutParameter for /bp/fleets/*/*/desired_config (redeploy compute stack).',
                    ],
                ], 502);
            }

            $fleet->template_slug = $nextTemplateSlug;
            $fleet->instance_types = [$nextInstanceType];
            $fleet->max_size = $template['max_size'];
            $fleet->warmup_seconds = $template['warmup_seconds'];
            $fleet->backlog_target = $template['backlog_target'];
            $fleet->scale_to_zero_minutes = $template['scale_to_zero_minutes'];
        }

        $fleet->save();

        $fleet = $this->attachFleetMetrics(collect([$fleet]))->first();

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

        $fleet = $this->attachFleetMetrics(collect([$fleet]))->first();

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

        $fleet = $this->attachFleetMetrics(collect([$fleet]))->first();

        return $this->sendResponse($fleet, 'Fleet bundle activated');
    }

    private function attachFleetMetrics($fleets)
    {
        $fleetCollection = collect($fleets);
        if ($fleetCollection->isEmpty()) {
            return $fleets;
        }

        return $fleetCollection
            ->groupBy('stage')
            ->flatMap(function ($group, $stage) {
                return $this->attachFleetMetricsForStage($group, (string) $stage);
            })
            ->values();
    }

    private function attachFleetMetricsForStage($fleets, string $stage)
    {
        $fleetCollection = collect($fleets);
        if ($fleetCollection->isEmpty()) {
            return $fleetCollection;
        }

        $fleetIds = $fleetCollection->pluck('id')->all();
        $fleetSlugs = $fleetCollection->pluck('slug')->all();

        $workerFleetSub = DB::connection('central')
            ->table('comfy_ui_workers as w')
            ->join('worker_workflows as ww', 'w.id', '=', 'ww.worker_id')
            ->join('comfyui_workflow_fleets as wf', 'wf.workflow_id', '=', 'ww.workflow_id')
            ->where('wf.stage', $stage)
            ->where('w.is_approved', true)
            ->where('w.last_seen_at', '>=', now()->subMinutes(5))
            ->whereIn('wf.fleet_id', $fleetIds)
            ->select('w.id as worker_id', 'wf.fleet_id', 'w.capacity_type')
            ->distinct();

        $workerCapacity = DB::connection('central')
            ->query()
            ->fromSub($workerFleetSub, 'x')
            ->selectRaw('fleet_id, capacity_type, COUNT(DISTINCT worker_id) as workers')
            ->groupBy('fleet_id', 'capacity_type')
            ->get();

        $capacityByFleet = [];
        foreach ($workerCapacity as $row) {
            $fleetId = (int) $row->fleet_id;
            if (!isset($capacityByFleet[$fleetId])) {
                $capacityByFleet[$fleetId] = ['spot' => 0, 'on-demand' => 0, 'unknown' => 0];
            }
            $capacityType = $row->capacity_type ?: 'unknown';
            $capacityByFleet[$fleetId][$capacityType] = (int) $row->workers;
        }

        $utilizationRows = DB::connection('central')
            ->table('comfyui_worker_sessions')
            ->where('stage', $stage)
            ->whereIn('fleet_slug', $fleetSlugs)
            ->where('started_at', '>=', now()->subHours(24))
            ->selectRaw('fleet_slug, SUM(busy_seconds) as busy_seconds, SUM(running_seconds) as running_seconds')
            ->groupBy('fleet_slug')
            ->get()
            ->keyBy('fleet_slug');

        return $fleetCollection->map(function ($fleet) use ($capacityByFleet, $utilizationRows) {
            $capacity = $capacityByFleet[$fleet->id] ?? ['spot' => 0, 'on-demand' => 0, 'unknown' => 0];
            $utilRow = $utilizationRows[$fleet->slug] ?? null;
            $runningSeconds = $utilRow ? (int) $utilRow->running_seconds : 0;
            $busySeconds = $utilRow ? (int) $utilRow->busy_seconds : 0;
            $utilization = $runningSeconds > 0 ? round($busySeconds / $runningSeconds, 4) : null;

            $fleet->setAttribute('spot_workers', $capacity['spot'] ?? 0);
            $fleet->setAttribute('on_demand_workers', $capacity['on-demand'] ?? 0);
            $fleet->setAttribute('unknown_workers', $capacity['unknown'] ?? 0);
            $fleet->setAttribute('busy_seconds', $busySeconds);
            $fleet->setAttribute('running_seconds', $runningSeconds);
            $fleet->setAttribute('utilization', $utilization);

            return $fleet;
        });
    }
}
