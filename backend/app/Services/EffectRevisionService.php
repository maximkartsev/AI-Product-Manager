<?php

namespace App\Services;

use App\Models\Effect;
use App\Models\EffectRevision;

class EffectRevisionService
{
    public function createSnapshot(Effect $effect, ?int $createdByUserId = null): EffectRevision
    {
        $effect->loadMissing('workflow', 'category');

        return EffectRevision::query()->create([
            'effect_id' => $effect->id,
            'workflow_id' => $effect->workflow_id,
            'category_id' => $effect->category_id,
            'publication_status' => $effect->publication_status,
            'property_overrides' => is_array($effect->property_overrides) ? $effect->property_overrides : null,
            'snapshot_json' => $this->buildSnapshotPayload($effect),
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    private function buildSnapshotPayload(Effect $effect): array
    {
        $snapshot = [
            'effect' => [
                'id' => $effect->id,
                'name' => $effect->name,
                'slug' => $effect->slug,
                'description' => $effect->description,
                'category_id' => $effect->category_id,
                'workflow_id' => $effect->workflow_id,
                'property_overrides' => $effect->property_overrides,
                'type' => $effect->type,
                'credits_cost' => $effect->credits_cost,
                'is_active' => (bool) $effect->is_active,
                'is_premium' => (bool) $effect->is_premium,
                'is_new' => (bool) $effect->is_new,
                'publication_status' => $effect->publication_status,
            ],
        ];

        if ($effect->workflow) {
            $snapshot['workflow'] = [
                'id' => $effect->workflow->id,
                'name' => $effect->workflow->name,
                'slug' => $effect->workflow->slug,
                'comfyui_workflow_path' => $effect->workflow->comfyui_workflow_path,
                'properties' => $effect->workflow->properties,
                'output_node_id' => $effect->workflow->output_node_id,
                'output_extension' => $effect->workflow->output_extension,
                'output_mime_type' => $effect->workflow->output_mime_type,
                'workload_kind' => $effect->workflow->workload_kind,
                'work_units_property_key' => $effect->workflow->work_units_property_key,
                'slo_p95_wait_seconds' => $effect->workflow->slo_p95_wait_seconds,
                'slo_video_seconds_per_processing_second_p95' => $effect->workflow->slo_video_seconds_per_processing_second_p95,
                'partner_cost_per_work_unit' => $effect->workflow->partner_cost_per_work_unit,
                'is_active' => (bool) $effect->workflow->is_active,
            ];
        }

        return $snapshot;
    }
}
