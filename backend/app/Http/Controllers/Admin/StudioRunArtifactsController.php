<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\RunArtifact;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioRunArtifactsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = RunArtifact::query()->orderByDesc('id');

        $artifactType = trim((string) $request->input('artifact_type', ''));
        if ($artifactType !== '') {
            $query->where('artifact_type', $artifactType);
        }

        $items = $query->get()
            ->map(fn (RunArtifact $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Run artifacts retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = RunArtifact::query()->find($id);
        if (!$item) {
            return $this->sendError('Run artifact not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Run artifact retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_test_run_id' => 'nullable|integer|min:1|exists:effect_test_runs,id',
            'load_test_run_id' => 'nullable|integer|min:1|exists:load_test_runs,id',
            'artifact_type' => 'required|string|max:64',
            'storage_disk' => 'nullable|string|max:64',
            'storage_path' => 'nullable|string|max:2048',
            'metadata_json' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        if (!isset($validated['effect_test_run_id']) && !isset($validated['load_test_run_id'])) {
            return $this->sendError(
                'Validation error.',
                ['effect_test_run_id' => ['Either effect_test_run_id or load_test_run_id is required.']],
                422
            );
        }

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return RunArtifact::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Run artifact created successfully', [], 201);
    }

    private function payload(RunArtifact $item): array
    {
        return [
            'id' => $item->id,
            'effect_test_run_id' => $item->effect_test_run_id,
            'load_test_run_id' => $item->load_test_run_id,
            'artifact_type' => $item->artifact_type,
            'storage_disk' => $item->storage_disk,
            'storage_path' => $item->storage_path,
            'metadata_json' => $item->metadata_json,
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
