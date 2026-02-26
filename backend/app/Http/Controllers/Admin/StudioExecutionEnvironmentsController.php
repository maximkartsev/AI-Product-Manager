<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ExecutionEnvironment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioExecutionEnvironmentsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = ExecutionEnvironment::query()->orderByDesc('id');

        $kind = trim((string) $request->input('kind', ''));
        if ($kind !== '') {
            $query->where('kind', $kind);
        }

        $stage = trim((string) $request->input('stage', ''));
        if ($stage !== '') {
            $query->where('stage', $stage);
        }

        $isActiveFilter = $this->parseBooleanFilter($request->input('is_active'));
        if ($isActiveFilter !== null) {
            $query->where('is_active', $isActiveFilter);
        }

        $items = $query->get()
            ->map(fn (ExecutionEnvironment $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Execution environments retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = ExecutionEnvironment::query()->find($id);
        if (!$item) {
            return $this->sendError('Execution environment not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Execution environment retrieved successfully');
    }

    private function payload(ExecutionEnvironment $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'kind' => $item->kind,
            'stage' => $item->stage,
            'fleet_slug' => $item->fleet_slug,
            'dev_node_id' => $item->dev_node_id,
            'configuration_json' => $item->configuration_json,
            'is_active' => (bool) $item->is_active,
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
