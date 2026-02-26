<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ProductionFleetSnapshot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioProductionFleetSnapshotsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductionFleetSnapshot::query()->orderByDesc('id');

        $stage = trim((string) $request->input('stage', ''));
        if ($stage !== '') {
            $query->where('stage', $stage);
        }

        $items = $query->get()
            ->map(fn (ProductionFleetSnapshot $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Production fleet snapshots retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = ProductionFleetSnapshot::query()->find($id);
        if (!$item) {
            return $this->sendError('Production fleet snapshot not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Production fleet snapshot retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'fleet_slug' => 'nullable|string|max:255',
            'stage' => 'nullable|string|in:production',
            'captured_at' => 'nullable|date',
            'config_json' => 'nullable|array',
            'composition_json' => 'nullable|array',
            'metrics_json' => 'nullable|array',
            'queue_depth' => 'nullable|integer|min:0',
            'queue_units' => 'nullable|numeric|min:0',
            'p95_queue_wait_seconds' => 'nullable|numeric|min:0',
            'p95_processing_seconds' => 'nullable|numeric|min:0',
            'interruptions_count' => 'nullable|integer|min:0',
            'rebalance_recommendations_count' => 'nullable|integer|min:0',
            'spot_discount_estimate' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $validated['stage'] = $validated['stage'] ?? 'production';
        $validated['captured_at'] = $validated['captured_at'] ?? now();

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return ProductionFleetSnapshot::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Production fleet snapshot created successfully', [], 201);
    }

    private function payload(ProductionFleetSnapshot $item): array
    {
        return [
            'id' => $item->id,
            'execution_environment_id' => $item->execution_environment_id,
            'fleet_slug' => $item->fleet_slug,
            'stage' => $item->stage,
            'captured_at' => $this->toIso8601($item->captured_at),
            'config_json' => $item->config_json,
            'composition_json' => $item->composition_json,
            'metrics_json' => $item->metrics_json,
            'queue_depth' => $item->queue_depth,
            'queue_units' => $item->queue_units,
            'p95_queue_wait_seconds' => $item->p95_queue_wait_seconds,
            'p95_processing_seconds' => $item->p95_processing_seconds,
            'interruptions_count' => $item->interruptions_count,
            'rebalance_recommendations_count' => $item->rebalance_recommendations_count,
            'spot_discount_estimate' => $item->spot_discount_estimate,
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
