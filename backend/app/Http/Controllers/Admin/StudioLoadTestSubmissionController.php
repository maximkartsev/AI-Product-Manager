<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\LoadTestRun;
use App\Services\LoadTesting\LoadTestSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudioLoadTestSubmissionController extends BaseController
{
    public function __construct(
        private readonly LoadTestSubmissionService $submissionService
    ) {
    }

    public function submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'load_test_run_id' => 'required|integer|min:1|exists:load_test_runs,id',
            'effect_revision_id' => 'required|integer|min:1|exists:effect_revisions,id',
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'input_file_id' => 'required|integer|min:1',
            'input_payload' => 'nullable|array',
            'count' => 'required|integer|min:1|max:2000',
            'acting_user_id' => 'nullable|integer|min:1|exists:users,id',
            'benchmark_context_id' => 'nullable|string|max:120',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $run = LoadTestRun::query()->find((int) $validated['load_test_run_id']);
        if (!$run) {
            return $this->sendError('Load test run not found.', [], 404);
        }

        if ((int) $run->execution_environment_id !== (int) $validated['execution_environment_id']) {
            return $this->sendError('Execution environment mismatch for this run.', [], 422);
        }

        if ((int) $run->effect_revision_id !== (int) $validated['effect_revision_id']) {
            return $this->sendError('Effect revision mismatch for this run.', [], 422);
        }

        try {
            $result = $this->submissionService->submitBatch(
                run: $run,
                effectRevisionId: (int) $validated['effect_revision_id'],
                executionEnvironmentId: (int) $validated['execution_environment_id'],
                inputFileId: (int) $validated['input_file_id'],
                inputPayload: (array) ($validated['input_payload'] ?? []),
                count: (int) $validated['count'],
                actingUserId: isset($validated['acting_user_id']) ? (int) $validated['acting_user_id'] : null,
                benchmarkContextId: $validated['benchmark_context_id'] ?? null
            );
        } catch (\Throwable $e) {
            return $this->sendError('Load test dispatch submission failed.', [
                'error' => $e->getMessage(),
            ], 422);
        }

        $metrics = is_array($run->metrics_json) ? $run->metrics_json : [];
        $metrics['source'] = 'studio_load_test';
        $metrics['last_submission'] = [
            'submitted_count' => (int) ($result['submitted_count'] ?? 0),
            'dispatch_ids' => (array) ($result['dispatch_ids'] ?? []),
            'submitted_at' => now()->toIso8601String(),
        ];
        $run->metrics_json = $metrics;
        $run->save();

        return $this->sendResponse($result, 'Load test dispatches submitted successfully.', [], 201);
    }
}
