<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\Effect;
use App\Services\EffectRevisionService;
use App\Services\WorkflowCloneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StudioEffectCloneController extends BaseController
{
    public function store(
        Request $request,
        int $id,
        WorkflowCloneService $workflowCloneService,
        EffectRevisionService $effectRevisionService
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'mode' => 'required|string|in:effect_only,effect_and_workflow',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $sourceEffect = Effect::query()->with('workflow')->find($id);
        if (!$sourceEffect) {
            return $this->sendError('Effect not found.', [], 404);
        }

        $mode = (string) $validator->validated()['mode'];
        $workflowPayload = null;
        $targetWorkflowId = (int) $sourceEffect->workflow_id;

        if ($mode === 'effect_and_workflow' && $sourceEffect->workflow) {
            $clonedWorkflow = $workflowCloneService->cloneWorkflow(
                $sourceEffect->workflow,
                $request->user()?->id ? (int) $request->user()->id : null
            );
            $targetWorkflowId = (int) $clonedWorkflow['workflow']->id;
            $workflowPayload = $clonedWorkflow['workflow'];
        }

        $attributes = $sourceEffect->only([
            'name',
            'slug',
            'description',
            'category_id',
            'property_overrides',
            'tags',
            'type',
            'thumbnail_url',
            'preview_video_url',
            'credits_cost',
            'last_processing_time_seconds',
            'popularity_score',
            'is_active',
            'is_premium',
            'is_new',
        ]);

        $attributes['name'] = trim(((string) ($attributes['name'] ?? 'Effect')) . ' Copy');
        $attributes['slug'] = $this->uniqueEffectSlug((string) ($attributes['slug'] ?? 'effect'));
        $attributes['workflow_id'] = $targetWorkflowId;
        $attributes['publication_status'] = 'development';
        $attributes['published_revision_id'] = null;
        $attributes['prod_execution_environment_id'] = null;

        $clonedEffect = Effect::query()->create($attributes);
        $revision = $effectRevisionService->createSnapshot(
            $clonedEffect,
            $request->user()?->id ? (int) $request->user()->id : null
        );

        $payload = [
            'effect' => $clonedEffect->fresh(),
            'effect_revision' => $revision,
        ];

        if ($workflowPayload) {
            $payload['workflow'] = $workflowPayload;
        }

        return $this->sendResponse($payload, 'Effect cloned successfully', [], 201);
    }

    private function uniqueEffectSlug(string $baseSlug): string
    {
        $normalized = Str::slug($baseSlug);
        if ($normalized === '') {
            $normalized = 'effect';
        }

        $candidate = $normalized . '-copy';
        $counter = 2;

        while (Effect::query()->where('slug', $candidate)->exists()) {
            $candidate = sprintf('%s-copy-%d', $normalized, $counter);
            $counter++;
        }

        return $candidate;
    }
}

