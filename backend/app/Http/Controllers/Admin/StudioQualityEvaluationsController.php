<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\QualityEvaluation;
use App\Services\Observability\ActionLogService;
use App\Services\Quality\QualityEvaluationService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudioQualityEvaluationsController extends BaseController
{
    public function __construct(
        private readonly QualityEvaluationService $qualityEvaluationService,
        private readonly ActionLogService $actionLogService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = QualityEvaluation::query()->orderByDesc('id');
        if ($request->filled('benchmark_matrix_run_id')) {
            $query->where('benchmark_matrix_run_id', (int) $request->input('benchmark_matrix_run_id'));
        }
        if ($request->filled('benchmark_context_id')) {
            $query->where('benchmark_context_id', (string) $request->input('benchmark_context_id'));
        }

        $items = $query->get()->map(fn (QualityEvaluation $evaluation) => $this->payload($evaluation))->values();

        return $this->sendResponse(['items' => $items], 'Quality evaluations retrieved successfully.');
    }

    public function show(int $id): JsonResponse
    {
        $evaluation = QualityEvaluation::query()->find($id);
        if (!$evaluation) {
            return $this->sendError('Quality evaluation not found.', [], 404);
        }

        return $this->sendResponse($this->payload($evaluation), 'Quality evaluation retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'input_ref' => 'required|string|max:2048',
            'output_ref' => 'required|string|max:2048',
            'provider' => 'nullable|string|in:gemini',
            'rubric_version' => 'nullable|string|max:40',
            'benchmark_matrix_run_id' => 'nullable|integer|min:1|exists:benchmark_matrix_runs,id',
            'benchmark_matrix_run_item_id' => 'nullable|integer|min:1|exists:benchmark_matrix_run_items,id',
            'effect_test_run_id' => 'nullable|integer|min:1|exists:effect_test_runs,id',
            'benchmark_context_id' => 'nullable|string|max:120',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        try {
            $evaluation = $this->qualityEvaluationService->evaluateAndStore($validated);
        } catch (\Throwable $e) {
            return $this->sendError('Quality evaluation failed.', [
                'error' => $e->getMessage(),
            ], 422);
        }

        if (is_string(data_get($evaluation->result_json, 'fallback_reason'))) {
            $this->actionLogService->log(
                severity: 'warn',
                event: 'quality_eval_fallback_used',
                module: 'quality_evaluation',
                message: 'Quality evaluation used fallback scoring path.',
                telemetrySink: 'admin',
                economicImpact: ['kind' => 'decision_confidence', 'estimated_usd_delta' => null],
                operatorAction: ['instruction' => 'Re-run evaluation when Gemini provider connectivity is restored.'],
                context: [
                    'quality_evaluation_id' => $evaluation->id,
                    'fallback_reason' => data_get($evaluation->result_json, 'fallback_reason'),
                ]
            );
        }

        return $this->sendResponse(
            $this->payload($evaluation),
            'Quality evaluation completed successfully.',
            [],
            201
        );
    }

    private function payload(QualityEvaluation $evaluation): array
    {
        return [
            'id' => $evaluation->id,
            'benchmark_matrix_run_id' => $evaluation->benchmark_matrix_run_id,
            'benchmark_matrix_run_item_id' => $evaluation->benchmark_matrix_run_item_id,
            'effect_test_run_id' => $evaluation->effect_test_run_id,
            'benchmark_context_id' => $evaluation->benchmark_context_id,
            'rubric_version' => $evaluation->rubric_version,
            'provider' => $evaluation->provider,
            'model' => $evaluation->model,
            'status' => $evaluation->status,
            'composite_score' => $evaluation->composite_score,
            'vector_json' => $evaluation->vector_json,
            'request_json' => $evaluation->request_json,
            'result_json' => $evaluation->result_json,
            'evaluated_at' => $this->toIso8601($evaluation->evaluated_at),
            'created_at' => $this->toIso8601($evaluation->created_at),
            'updated_at' => $this->toIso8601($evaluation->updated_at),
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

