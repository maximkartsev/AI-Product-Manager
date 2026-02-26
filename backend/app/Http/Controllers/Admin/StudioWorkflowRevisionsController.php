<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\Workflow;
use App\Models\WorkflowRevision;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudioWorkflowRevisionsController extends BaseController
{
    public function index(int $id): JsonResponse
    {
        $workflow = Workflow::query()->find($id);
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $items = WorkflowRevision::query()
            ->where('workflow_id', $workflow->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (WorkflowRevision $revision) => $this->payload($revision))
            ->values();

        return $this->sendResponse(['items' => $items], 'Workflow revisions retrieved successfully');
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $workflow = Workflow::query()->find($id);
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $snapshotJson = $this->loadWorkflowJson($workflow);
        $revision = DB::connection('central')->transaction(function () use ($workflow, $snapshotJson, $request) {
            return WorkflowRevision::query()->create([
                'workflow_id' => $workflow->id,
                'comfyui_workflow_path' => $workflow->comfyui_workflow_path,
                'snapshot_json' => $snapshotJson,
                'created_by_user_id' => $request->user()?->id ? (int) $request->user()->id : null,
            ]);
        });

        return $this->sendResponse($this->payload($revision), 'Workflow revision created successfully', [], 201);
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
        return is_array($decoded) ? $decoded : null;
    }

    private function payload(WorkflowRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'workflow_id' => $revision->workflow_id,
            'comfyui_workflow_path' => $revision->comfyui_workflow_path,
            'snapshot_json' => $revision->snapshot_json,
            'created_by_user_id' => $revision->created_by_user_id,
            'created_at' => $this->toIso8601($revision->created_at),
            'updated_at' => $this->toIso8601($revision->updated_at),
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

