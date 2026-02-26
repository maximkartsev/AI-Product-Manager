<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\Workflow;
use App\Models\WorkflowAnalysisJob;
use App\Services\WorkflowAnalyzerService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class StudioWorkflowAnalysisController extends BaseController
{
    public function store(Request $request, WorkflowAnalyzerService $analyzer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'workflow_id' => 'nullable|integer|exists:workflows,id',
            'workflow_json' => 'nullable|array',
            'requested_output_kind' => 'nullable|string|in:image,video,audio',
            'example_io_description' => 'nullable|string|max:4000',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $workflowId = $validated['workflow_id'] ?? null;
        $workflowJson = $validated['workflow_json'] ?? null;

        if (!$workflowId && !$workflowJson) {
            return $this->sendError('Either workflow_id or workflow_json is required.', [], 422);
        }

        if (!$workflowJson) {
            $workflow = Workflow::query()->find($workflowId);
            if (!$workflow) {
                return $this->sendError('Workflow not found.', [], 404);
            }
            $workflowJson = $this->loadWorkflowJson($workflow);
            if (!$workflowJson) {
                return $this->sendError('Workflow JSON is invalid or empty.', [], 422);
            }
        }

        $job = WorkflowAnalysisJob::query()->create([
            'workflow_id' => $workflowId,
            'status' => 'running',
            'analyzer_prompt_version' => $analyzer->promptVersion(),
            'analyzer_schema_version' => $analyzer->schemaVersion(),
            'requested_output_kind' => $validated['requested_output_kind'] ?? null,
            'input_json' => [
                'workflow_json' => $workflowJson,
                'example_io_description' => $validated['example_io_description'] ?? null,
            ],
            'created_by_user_id' => $request->user()?->id,
        ]);

        try {
            $result = $analyzer->analyze(
                $workflowJson,
                $validated['requested_output_kind'] ?? null,
                $validated['example_io_description'] ?? null
            );

            $job->status = 'completed';
            $job->result_json = $result;
            $job->completed_at = now();
            $job->save();
        } catch (\Throwable $e) {
            $job->status = 'failed';
            $job->error_message = $e->getMessage();
            $job->completed_at = now();
            $job->save();

            return $this->sendError('Workflow analysis failed.', [
                'job_id' => $job->id,
                'error' => $job->error_message,
            ], 422);
        }

        return $this->sendResponse($this->jobPayload($job), 'Workflow analysis completed');
    }

    public function show(int $id): JsonResponse
    {
        $job = WorkflowAnalysisJob::query()->find($id);
        if (!$job) {
            return $this->sendError('Workflow analysis job not found.', [], 404);
        }

        return $this->sendResponse($this->jobPayload($job), 'Workflow analysis job retrieved');
    }

    private function loadWorkflowJson(Workflow $workflow): ?array
    {
        $path = (string) ($workflow->comfyui_workflow_path ?? '');
        if ($path === '') {
            return null;
        }

        $disk = (string) config('services.comfyui.workflow_disk', 's3');
        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        $raw = Storage::disk($disk)->get($path);
        $json = json_decode($raw ?: '', true);

        return is_array($json) && !empty($json) ? $json : null;
    }

    private function jobPayload(WorkflowAnalysisJob $job): array
    {
        return [
            'id' => $job->id,
            'workflow_id' => $job->workflow_id,
            'status' => $job->status,
            'analyzer_prompt_version' => $job->analyzer_prompt_version,
            'analyzer_schema_version' => $job->analyzer_schema_version,
            'requested_output_kind' => $job->requested_output_kind,
            'input_json' => $job->input_json,
            'result_json' => $job->result_json,
            'error_message' => $job->error_message,
            'created_by_user_id' => $job->created_by_user_id,
            'completed_at' => $this->toIso8601($job->completed_at),
            'created_at' => $this->toIso8601($job->created_at),
            'updated_at' => $this->toIso8601($job->updated_at),
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
