<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Services\StudioBlackboxRunnerService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudioBlackboxRunsController extends BaseController
{
    public function __construct(
        private readonly StudioBlackboxRunnerService $blackboxRunnerService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_id' => 'required|integer|min:1|exists:effects,id',
            'effect_revision_id' => 'required|integer|min:1|exists:effect_revisions,id',
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'input_file_id' => 'required|integer|min:1',
            'input_payload' => 'nullable|array',
            'count' => 'nullable|integer|min:1|max:200',
            'run_counts' => 'nullable|array|min:1',
            'run_counts.*' => 'integer|min:1',
            'cost_model' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $effect = Effect::query()->find((int) $validated['effect_id']);
        $revision = EffectRevision::query()->find((int) $validated['effect_revision_id']);
        $environment = ExecutionEnvironment::query()->find((int) $validated['execution_environment_id']);

        if (!$effect || !$revision) {
            return $this->sendError('Effect or revision not found.', [], 422);
        }
        if ((int) $revision->effect_id !== (int) $effect->id) {
            return $this->sendError('Revision does not belong to effect.', [], 422);
        }

        if (!$environment || $environment->kind !== 'test_asg' || !$environment->is_active) {
            return $this->sendError('Execution environment must be an active test_asg environment.', [], 422);
        }

        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $count = (int) ($validated['count'] ?? 1);
        $run = EffectTestRun::query()->create([
            'effect_id' => $effect->id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'run_mode' => 'blackbox',
            'target_count' => $count,
            'overrides_json' => is_array($validated['input_payload'] ?? null) ? $validated['input_payload'] : null,
            'status' => 'queued',
            'created_by_user_id' => (int) $user->id,
        ]);

        try {
            $result = $this->blackboxRunnerService->run(
                $run,
                $effect,
                $revision,
                $environment,
                $user,
                (int) $validated['input_file_id'],
                is_array($validated['input_payload'] ?? null) ? $validated['input_payload'] : [],
                $count,
                [
                    'run_counts' => $validated['run_counts'] ?? null,
                    ...(is_array($validated['cost_model'] ?? null) ? $validated['cost_model'] : []),
                ]
            );
        } catch (\Throwable $e) {
            $failedRun = $this->blackboxRunnerService->markFailed($run, (string) $e->getMessage());
            return $this->sendError('Blackbox run failed.', [
                'run_id' => $failedRun->id,
                'error' => $e->getMessage(),
            ], 422);
        }

        return $this->sendResponse([
            'run' => $this->runPayload($result['run']),
            'job_ids' => $result['job_ids'],
            'dispatch_ids' => $result['dispatch_ids'],
            'dispatch_count' => $result['dispatch_count'],
            'cost_report' => $result['cost_report'],
        ], 'Blackbox run queued successfully', [], 201);
    }

    private function runPayload(EffectTestRun $run): array
    {
        return [
            'id' => $run->id,
            'effect_id' => $run->effect_id,
            'effect_revision_id' => $run->effect_revision_id,
            'execution_environment_id' => $run->execution_environment_id,
            'run_mode' => $run->run_mode,
            'target_count' => $run->target_count,
            'status' => $run->status,
            'metrics_json' => $run->metrics_json,
            'created_by_user_id' => $run->created_by_user_id,
            'started_at' => $this->toIso8601($run->started_at),
            'completed_at' => $this->toIso8601($run->completed_at),
            'created_at' => $this->toIso8601($run->created_at),
            'updated_at' => $this->toIso8601($run->updated_at),
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

