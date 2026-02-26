<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\Workflow;
use App\Services\WorkflowCloneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioWorkflowCloneController extends BaseController
{
    public function store(Request $request, int $id, WorkflowCloneService $workflowCloneService): JsonResponse
    {
        $workflow = Workflow::query()->find($id);
        if (!$workflow) {
            return $this->sendError('Workflow not found.', [], 404);
        }

        $cloned = $workflowCloneService->cloneWorkflow($workflow, $request->user()?->id ? (int) $request->user()->id : null);

        return $this->sendResponse([
            'workflow' => $cloned['workflow'],
            'workflow_revision' => $cloned['workflow_revision'],
        ], 'Workflow cloned successfully', [], 201);
    }
}

