<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\Workflow;
use App\Models\WorkflowRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StudioWorkflowJsonController extends BaseController
{
    public function show(int $id): JsonResponse
    {
        $workflow = Workflow::query()->find($id);
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $workflowJson = $this->loadWorkflowJson($workflow);
        if ($workflowJson === null) {
            return $this->sendError('Workflow JSON is invalid or empty.', [], 422);
        }

        return $this->sendResponse([
            'workflow_id' => $workflow->id,
            'comfyui_workflow_path' => $workflow->comfyui_workflow_path,
            'workflow_json' => $workflowJson,
        ], 'Workflow JSON retrieved successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $workflow = Workflow::query()->find($id);
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'workflow_json' => 'required|array|min:1',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $workflowJson = $validated['workflow_json'];

        $disk = (string) config('services.comfyui.workflow_disk', 's3');
        $path = sprintf('workflows/%d/revisions/%s.json', $workflow->id, (string) Str::uuid());
        Storage::disk($disk)->put($path, json_encode($workflowJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $revision = DB::connection('central')->transaction(function () use ($workflow, $path, $workflowJson, $request) {
            $workflow->comfyui_workflow_path = $path;
            $workflow->save();

            return WorkflowRevision::query()->create([
                'workflow_id' => $workflow->id,
                'comfyui_workflow_path' => $path,
                'snapshot_json' => $workflowJson,
                'created_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
            ]);
        });

        return $this->sendResponse([
            'workflow_id' => $workflow->id,
            'comfyui_workflow_path' => $path,
            'workflow_json' => $workflowJson,
            'workflow_revision' => [
                'id' => $revision->id,
                'workflow_id' => $revision->workflow_id,
                'comfyui_workflow_path' => $revision->comfyui_workflow_path,
                'created_by_user_id' => $revision->created_by_user_id,
            ],
        ], 'Workflow JSON updated successfully');
    }

    private function loadWorkflowJson(Workflow $workflow): ?array
    {
        $path = trim((string) ($workflow->comfyui_workflow_path ?? ''));
        if ($path === '') {
            return null;
        }

        $disk = (string) config('services.comfyui.workflow_disk', 's3');
        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        $raw = Storage::disk($disk)->get($path);
        $decoded = json_decode($raw ?: '', true);

        return is_array($decoded) && !empty($decoded) ? $decoded : null;
    }
}

