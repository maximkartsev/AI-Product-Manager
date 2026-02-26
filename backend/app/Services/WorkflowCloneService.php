<?php

namespace App\Services;

use App\Models\Workflow;
use App\Models\WorkflowRevision;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkflowCloneService
{
    /**
     * @return array{workflow: Workflow, workflow_revision: WorkflowRevision}
     */
    public function cloneWorkflow(Workflow $source, ?int $createdByUserId = null): array
    {
        $attributes = $source->only([
            'name',
            'slug',
            'description',
            'properties',
            'output_node_id',
            'output_extension',
            'output_mime_type',
            'workload_kind',
            'work_units_property_key',
            'slo_p95_wait_seconds',
            'slo_video_seconds_per_processing_second_p95',
            'partner_cost_per_work_unit',
            'is_active',
        ]);

        $attributes['name'] = trim(((string) ($attributes['name'] ?? 'Workflow')) . ' Copy');
        $attributes['slug'] = $this->uniqueWorkflowSlug((string) ($attributes['slug'] ?? 'workflow'));
        $attributes['comfyui_workflow_path'] = null;

        $workflow = Workflow::query()->create($attributes);

        [$path, $snapshotJson] = $this->copyWorkflowJson($source, $workflow->id);
        if ($path !== null) {
            $workflow->comfyui_workflow_path = $path;
            $workflow->save();
        }

        $revision = WorkflowRevision::query()->create([
            'workflow_id' => $workflow->id,
            'comfyui_workflow_path' => $workflow->comfyui_workflow_path,
            'snapshot_json' => $snapshotJson,
            'created_by_user_id' => $createdByUserId,
        ]);

        return [
            'workflow' => $workflow->fresh(),
            'workflow_revision' => $revision,
        ];
    }

    /**
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function copyWorkflowJson(Workflow $source, int $targetWorkflowId): array
    {
        $sourcePath = trim((string) ($source->comfyui_workflow_path ?? ''));
        if ($sourcePath === '') {
            return [null, null];
        }

        $disk = (string) config('services.comfyui.workflow_disk', 's3');
        if (!Storage::disk($disk)->exists($sourcePath)) {
            return [null, null];
        }

        $raw = Storage::disk($disk)->get($sourcePath) ?: '';
        $decoded = json_decode($raw, true);
        $snapshotJson = is_array($decoded) ? $decoded : null;

        $targetPath = sprintf('workflows/%d/revisions/%s.json', $targetWorkflowId, (string) Str::uuid());
        Storage::disk($disk)->put($targetPath, $raw);

        return [$targetPath, $snapshotJson];
    }

    private function uniqueWorkflowSlug(string $baseSlug): string
    {
        $normalized = Str::slug($baseSlug);
        if ($normalized === '') {
            $normalized = 'workflow';
        }

        $candidate = $normalized . '-copy';
        $counter = 2;

        while (Workflow::query()->where('slug', $candidate)->exists()) {
            $candidate = sprintf('%s-copy-%d', $normalized, $counter);
            $counter++;
        }

        return $candidate;
    }
}

