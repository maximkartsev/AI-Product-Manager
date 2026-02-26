<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\LoadTestRun;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioLoadTestRunsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = LoadTestRun::query()->orderByDesc('id');

        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $items = $query->get()
            ->map(fn (LoadTestRun $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Load test runs retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = LoadTestRun::query()->find($id);
        if (!$item) {
            return $this->sendError('Load test run not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Load test run retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'load_test_scenario_id' => 'required|integer|min:1|exists:load_test_scenarios,id',
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'effect_revision_id' => 'nullable|integer|min:1|exists:effect_revisions,id',
            'experiment_variant_id' => 'nullable|integer|min:1|exists:experiment_variants,id',
            'fleet_config_snapshot_start_id' => 'nullable|integer|min:1|exists:fleet_config_snapshots,id',
            'fleet_config_snapshot_end_id' => 'nullable|integer|min:1|exists:fleet_config_snapshots,id',
            'status' => 'nullable|string|in:queued,running,completed,failed',
            'achieved_rpm' => 'nullable|numeric|min:0',
            'achieved_rps' => 'nullable|numeric|min:0',
            'success_count' => 'nullable|integer|min:0',
            'failure_count' => 'nullable|integer|min:0',
            'p95_latency_ms' => 'nullable|numeric|min:0',
            'queue_wait_p95_seconds' => 'nullable|numeric|min:0',
            'processing_p95_seconds' => 'nullable|numeric|min:0',
            'compute_cost_usd' => 'nullable|numeric|min:0',
            'effective_cost_usd' => 'nullable|numeric|min:0',
            'partner_cost_usd' => 'nullable|numeric|min:0',
            'margin_usd' => 'nullable|numeric',
            'metrics_json' => 'nullable|array',
            'started_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $validated['status'] = $validated['status'] ?? 'queued';
        $validated['created_by_user_id'] = $request->user()?->id ? (int) $request->user()->id : null;

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return LoadTestRun::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Load test run created successfully', [], 201);
    }

    private function payload(LoadTestRun $item): array
    {
        return [
            'id' => $item->id,
            'load_test_scenario_id' => $item->load_test_scenario_id,
            'execution_environment_id' => $item->execution_environment_id,
            'effect_revision_id' => $item->effect_revision_id,
            'experiment_variant_id' => $item->experiment_variant_id,
            'fleet_config_snapshot_start_id' => $item->fleet_config_snapshot_start_id,
            'fleet_config_snapshot_end_id' => $item->fleet_config_snapshot_end_id,
            'status' => $item->status,
            'achieved_rpm' => $item->achieved_rpm,
            'achieved_rps' => $item->achieved_rps,
            'success_count' => $item->success_count,
            'failure_count' => $item->failure_count,
            'p95_latency_ms' => $item->p95_latency_ms,
            'queue_wait_p95_seconds' => $item->queue_wait_p95_seconds,
            'processing_p95_seconds' => $item->processing_p95_seconds,
            'compute_cost_usd' => $item->compute_cost_usd,
            'effective_cost_usd' => $item->effective_cost_usd,
            'partner_cost_usd' => $item->partner_cost_usd,
            'margin_usd' => $item->margin_usd,
            'metrics_json' => $item->metrics_json,
            'started_at' => $this->toIso8601($item->started_at),
            'completed_at' => $this->toIso8601($item->completed_at),
            'created_by_user_id' => $item->created_by_user_id,
            'created_at' => $this->toIso8601($item->created_at),
            'updated_at' => $this->toIso8601($item->updated_at),
        ];
    }

    private function toIso8601(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable $e) {
                return $value;
            }
        }

        return null;
    }
}
