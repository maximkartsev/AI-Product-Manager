<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\BenchmarkMatrixRun;
use App\Services\Variants\BenchmarkMatrixRunnerService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class StudioBenchmarkMatrixRunsController extends BaseController
{
    public function __construct(
        private readonly BenchmarkMatrixRunnerService $runnerService
    ) {
    }

    public function index(): JsonResponse
    {
        $items = BenchmarkMatrixRun::query()
            ->with('items')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BenchmarkMatrixRun $run) => $this->payload($run))
            ->values();

        return $this->sendResponse(['items' => $items], 'Benchmark matrix runs retrieved successfully.');
    }

    public function show(int $id): JsonResponse
    {
        $run = BenchmarkMatrixRun::query()->with('items')->find($id);
        if (!$run) {
            return $this->sendError('Benchmark matrix run not found.', [], 404);
        }

        return $this->sendResponse($this->payload($run), 'Benchmark matrix run retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_revision_id' => 'required|integer|min:1|exists:effect_revisions,id',
            'stage' => 'nullable|string|in:staging,production',
            'input_file_id' => 'required|integer|min:1',
            'input_payload' => 'nullable|array',
            'runs_per_variant' => 'nullable|integer|min:1|max:100',
            'cost_model' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $stage = (string) ($validated['stage'] ?? 'staging');
        $benchmarkContextId = 'bm_' . Str::lower((string) Str::uuid());
        $run = BenchmarkMatrixRun::query()->create([
            'benchmark_context_id' => $benchmarkContextId,
            'effect_revision_id' => (int) $validated['effect_revision_id'],
            'stage' => $stage,
            'status' => 'queued',
            'runs_per_variant' => (int) ($validated['runs_per_variant'] ?? 1),
            'variant_count' => 0,
            'created_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
            'metrics_json' => [
                'input_file_id' => (int) $validated['input_file_id'],
                'input_payload' => (array) ($validated['input_payload'] ?? []),
            ],
        ]);

        try {
            $run = $this->runnerService->run(
                matrixRun: $run,
                inputFileId: (int) $validated['input_file_id'],
                inputPayload: (array) ($validated['input_payload'] ?? []),
                runsPerVariant: (int) ($validated['runs_per_variant'] ?? 1),
                costModelInput: (array) ($validated['cost_model'] ?? [])
            );
        } catch (\Throwable $e) {
            $run->status = 'failed';
            $run->completed_at = now();
            $run->metrics_json = array_merge($run->metrics_json ?? [], [
                'error' => $e->getMessage(),
            ]);
            $run->save();

            return $this->sendError('Benchmark matrix run failed.', [
                'benchmark_matrix_run_id' => $run->id,
                'error' => $e->getMessage(),
            ], 422);
        }

        return $this->sendResponse(
            $this->payload($run->fresh('items')),
            'Benchmark matrix run queued successfully.',
            [],
            201
        );
    }

    private function payload(BenchmarkMatrixRun $run): array
    {
        return [
            'id' => $run->id,
            'benchmark_context_id' => $run->benchmark_context_id,
            'effect_revision_id' => $run->effect_revision_id,
            'stage' => $run->stage,
            'status' => $run->status,
            'runs_per_variant' => $run->runs_per_variant,
            'variant_count' => $run->variant_count,
            'metrics_json' => $run->metrics_json,
            'created_by_user_id' => $run->created_by_user_id,
            'started_at' => $this->toIso8601($run->started_at),
            'completed_at' => $this->toIso8601($run->completed_at),
            'created_at' => $this->toIso8601($run->created_at),
            'updated_at' => $this->toIso8601($run->updated_at),
            'items' => $run->relationLoaded('items')
                ? $run->items->map(fn ($item) => [
                    'id' => $item->id,
                    'variant_id' => $item->variant_id,
                    'execution_environment_id' => $item->execution_environment_id,
                    'experiment_variant_id' => $item->experiment_variant_id,
                    'effect_test_run_id' => $item->effect_test_run_id,
                    'dispatch_count' => $item->dispatch_count,
                    'status' => $item->status,
                    'metrics_json' => $item->metrics_json,
                    'created_at' => $this->toIso8601($item->created_at),
                ])->values()
                : [],
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
            } catch (\Throwable) {
                return $value;
            }
        }

        return null;
    }
}

