<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\FleetConfigSnapshot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioFleetConfigSnapshotsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = FleetConfigSnapshot::query()->orderByDesc('id');

        $scope = trim((string) $request->input('snapshot_scope', ''));
        if ($scope !== '') {
            $query->where('snapshot_scope', $scope);
        }

        $items = $query->get()
            ->map(fn (FleetConfigSnapshot $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Fleet config snapshots retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = FleetConfigSnapshot::query()->find($id);
        if (!$item) {
            return $this->sendError('Fleet config snapshot not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Fleet config snapshot retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'experiment_variant_id' => 'nullable|integer|min:1|exists:experiment_variants,id',
            'snapshot_scope' => 'nullable|string|in:run_start,run_end,periodic,manual',
            'config_json' => 'nullable|array',
            'composition_json' => 'nullable|array',
            'captured_at' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $validated['snapshot_scope'] = $validated['snapshot_scope'] ?? 'manual';
        $validated['captured_at'] = $validated['captured_at'] ?? now();

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return FleetConfigSnapshot::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Fleet config snapshot created successfully', [], 201);
    }

    private function payload(FleetConfigSnapshot $item): array
    {
        return [
            'id' => $item->id,
            'execution_environment_id' => $item->execution_environment_id,
            'experiment_variant_id' => $item->experiment_variant_id,
            'snapshot_scope' => $item->snapshot_scope,
            'config_json' => $item->config_json,
            'composition_json' => $item->composition_json,
            'captured_at' => $this->toIso8601($item->captured_at),
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
