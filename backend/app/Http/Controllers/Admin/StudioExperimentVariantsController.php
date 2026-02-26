<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ExperimentVariant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioExperimentVariantsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = ExperimentVariant::query()->orderByDesc('id');

        $isActiveFilter = $this->parseBooleanFilter($request->input('is_active'));
        if ($isActiveFilter !== null) {
            $query->where('is_active', $isActiveFilter);
        }

        $items = $query->get()
            ->map(fn (ExperimentVariant $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Experiment variants retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = ExperimentVariant::query()->find($id);
        if (!$item) {
            return $this->sendError('Experiment variant not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Experiment variant retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'target_environment_kind' => 'nullable|string|in:test_asg',
            'fleet_config_intent_json' => 'nullable|array',
            'constraints_json' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $validated['target_environment_kind'] = $validated['target_environment_kind'] ?? 'test_asg';
        $validated['is_active'] = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;
        $validated['created_by_user_id'] = $request->user()?->id ? (int) $request->user()->id : null;

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return ExperimentVariant::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Experiment variant created successfully', [], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = ExperimentVariant::query()->find($id);
        if (!$item) {
            return $this->sendError('Experiment variant not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'target_environment_kind' => 'sometimes|required|string|in:test_asg',
            'fleet_config_intent_json' => 'nullable|array',
            'constraints_json' => 'nullable|array',
            'is_active' => 'sometimes|required|boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        DB::connection('central')->transaction(function () use ($item, $validated) {
            $item->fill($validated);
            $item->save();
        });

        return $this->sendResponse($this->payload($item->fresh()), 'Experiment variant updated successfully');
    }

    private function payload(ExperimentVariant $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'target_environment_kind' => $item->target_environment_kind,
            'fleet_config_intent_json' => $item->fleet_config_intent_json,
            'constraints_json' => $item->constraints_json,
            'is_active' => (bool) $item->is_active,
            'created_by_user_id' => $item->created_by_user_id,
            'created_at' => $this->toIso8601($item->created_at),
            'updated_at' => $this->toIso8601($item->updated_at),
        ];
    }

    private function parseBooleanFilter(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return null;
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
