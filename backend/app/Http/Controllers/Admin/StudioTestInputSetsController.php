<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\TestInputSet;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioTestInputSetsController extends BaseController
{
    public function index(): JsonResponse
    {
        $items = TestInputSet::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (TestInputSet $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Test input sets retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = TestInputSet::query()->find($id);
        if (!$item) {
            return $this->sendError('Test input set not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Test input set retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'input_json' => 'required|array|min:1',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $validated['created_by_user_id'] = $request->user()?->id ? (int) $request->user()->id : null;

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return TestInputSet::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Test input set created successfully', [], 201);
    }

    private function payload(TestInputSet $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'input_json' => $item->input_json,
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
