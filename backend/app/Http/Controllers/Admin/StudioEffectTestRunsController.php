<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\EffectTestRun;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioEffectTestRunsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = EffectTestRun::query()->orderByDesc('id');

        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $runMode = trim((string) $request->input('run_mode', ''));
        if ($runMode !== '') {
            $query->where('run_mode', $runMode);
        }

        $items = $query->get()
            ->map(fn (EffectTestRun $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Effect test runs retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = EffectTestRun::query()->find($id);
        if (!$item) {
            return $this->sendError('Effect test run not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Effect test run retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_id' => 'nullable|integer|min:1|exists:effects,id',
            'effect_revision_id' => 'required|integer|min:1|exists:effect_revisions,id',
            'workflow_revision_id' => 'nullable|integer|min:1|exists:workflow_revisions,id',
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'test_input_set_id' => 'nullable|integer|min:1|exists:test_input_sets,id',
            'run_mode' => 'required|string|in:interactive,blackbox',
            'target_count' => 'required|integer|min:1|max:100000',
            'overrides_json' => 'nullable|array',
            'status' => 'nullable|string|in:queued,running,completed,failed',
            'started_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'p50_latency_ms' => 'nullable|numeric|min:0',
            'p95_latency_ms' => 'nullable|numeric|min:0',
            'p99_latency_ms' => 'nullable|numeric|min:0',
            'error_rate_percent' => 'nullable|numeric|min:0',
            'compute_cost_usd' => 'nullable|numeric|min:0',
            'effective_cost_usd' => 'nullable|numeric|min:0',
            'partner_cost_usd' => 'nullable|numeric|min:0',
            'margin_usd' => 'nullable|numeric',
            'metrics_json' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $validated['status'] = $validated['status'] ?? 'queued';
        $validated['created_by_user_id'] = $request->user()?->id ? (int) $request->user()->id : null;

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return EffectTestRun::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Effect test run created successfully', [], 201);
    }

    private function payload(EffectTestRun $item): array
    {
        return [
            'id' => $item->id,
            'effect_id' => $item->effect_id,
            'effect_revision_id' => $item->effect_revision_id,
            'workflow_revision_id' => $item->workflow_revision_id,
            'execution_environment_id' => $item->execution_environment_id,
            'test_input_set_id' => $item->test_input_set_id,
            'run_mode' => $item->run_mode,
            'target_count' => $item->target_count,
            'overrides_json' => $item->overrides_json,
            'status' => $item->status,
            'started_at' => $this->toIso8601($item->started_at),
            'completed_at' => $this->toIso8601($item->completed_at),
            'p50_latency_ms' => $item->p50_latency_ms,
            'p95_latency_ms' => $item->p95_latency_ms,
            'p99_latency_ms' => $item->p99_latency_ms,
            'error_rate_percent' => $item->error_rate_percent,
            'compute_cost_usd' => $item->compute_cost_usd,
            'effective_cost_usd' => $item->effective_cost_usd,
            'partner_cost_usd' => $item->partner_cost_usd,
            'margin_usd' => $item->margin_usd,
            'metrics_json' => $item->metrics_json,
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
