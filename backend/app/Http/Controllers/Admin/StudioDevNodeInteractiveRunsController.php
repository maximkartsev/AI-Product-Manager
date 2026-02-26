<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\RunArtifact;
use App\Services\DevNodeInteractiveRunService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class StudioDevNodeInteractiveRunsController extends BaseController
{
    public function __construct(
        private readonly DevNodeInteractiveRunService $interactiveRunService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_revision_id' => 'required|integer|min:1|exists:effect_revisions,id',
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'test_input_set_id' => 'nullable|integer|min:1|exists:test_input_sets,id',
            'input_payload' => 'required|array|min:1',
            'input_payload.input_path' => 'required|string|max:2048',
            'input_payload.input_disk' => 'nullable|string|max:128',
            'input_payload.input_name' => 'nullable|string|max:255',
            'input_payload.input_mime_type' => 'nullable|string|max:255',
            'input_payload.properties' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        $revision = EffectRevision::query()
            ->with('effect')
            ->find((int) $validated['effect_revision_id']);
        if (!$revision || !$revision->effect) {
            return $this->sendError('Effect revision not found.', [], 422);
        }

        $environment = ExecutionEnvironment::query()
            ->with('devNode')
            ->find((int) $validated['execution_environment_id']);
        if (!$environment || $environment->kind !== 'dev_node' || !$environment->is_active) {
            return $this->sendError('Execution environment must be an active dev_node environment.', [], 422);
        }

        $devNode = $environment->devNode;
        if (
            !$devNode
            || (string) $devNode->status !== 'ready'
            || trim((string) ($devNode->public_endpoint ?: $devNode->private_endpoint)) === ''
        ) {
            return $this->sendError('Execution environment dev node must be ready with an endpoint.', [], 422);
        }

        $run = EffectTestRun::query()->create([
            'effect_id' => $revision->effect_id,
            'effect_revision_id' => $revision->id,
            'execution_environment_id' => $environment->id,
            'test_input_set_id' => $validated['test_input_set_id'] ?? null,
            'run_mode' => 'interactive',
            'target_count' => 1,
            'overrides_json' => is_array($validated['input_payload']) ? $validated['input_payload'] : null,
            'status' => 'queued',
            'created_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
        ]);

        try {
            $result = $this->interactiveRunService->execute(
                $run,
                $revision,
                $environment,
                is_array($validated['input_payload']) ? $validated['input_payload'] : []
            );

            return $this->sendResponse([
                'run' => $this->runPayload($result['run']),
                'artifacts' => collect($result['artifacts'] ?? [])
                    ->map(fn (RunArtifact $artifact) => $this->artifactPayload($artifact))
                    ->values(),
            ], 'Interactive run completed successfully');
        } catch (\Throwable $e) {
            $failedRun = $this->interactiveRunService->markFailed($run, (string) $e->getMessage());

            return $this->sendError('Interactive run failed.', [
                'run_id' => $failedRun->id,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function runPayload(EffectTestRun $run): array
    {
        return [
            'id' => $run->id,
            'effect_id' => $run->effect_id,
            'effect_revision_id' => $run->effect_revision_id,
            'execution_environment_id' => $run->execution_environment_id,
            'test_input_set_id' => $run->test_input_set_id,
            'run_mode' => $run->run_mode,
            'target_count' => $run->target_count,
            'overrides_json' => $run->overrides_json,
            'status' => $run->status,
            'metrics_json' => $run->metrics_json,
            'started_at' => $this->toIso8601($run->started_at),
            'completed_at' => $this->toIso8601($run->completed_at),
            'created_at' => $this->toIso8601($run->created_at),
            'updated_at' => $this->toIso8601($run->updated_at),
        ];
    }

    private function artifactPayload(RunArtifact $artifact): array
    {
        $previewUrl = null;
        if ($artifact->storage_disk && $artifact->storage_path) {
            try {
                $previewUrl = Storage::disk($artifact->storage_disk)->url($artifact->storage_path);
            } catch (\Throwable) {
                $previewUrl = null;
            }
        }

        return [
            'id' => $artifact->id,
            'effect_test_run_id' => $artifact->effect_test_run_id,
            'artifact_type' => $artifact->artifact_type,
            'storage_disk' => $artifact->storage_disk,
            'storage_path' => $artifact->storage_path,
            'preview_url' => $previewUrl,
            'metadata_json' => $artifact->metadata_json,
            'created_at' => $this->toIso8601($artifact->created_at),
            'updated_at' => $this->toIso8601($artifact->updated_at),
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

