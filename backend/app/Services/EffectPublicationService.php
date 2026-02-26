<?php

namespace App\Services;

use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\ExecutionEnvironment;

class EffectPublicationService
{
    public function synchronizePublishedBinding(
        Effect $effect,
        ?int $publishedRevisionId = null,
        ?int $prodExecutionEnvironmentId = null,
        ?int $actorUserId = null
    ): Effect {
        $workflowId = (int) ($effect->workflow_id ?? 0);
        if ($workflowId <= 0) {
            throw new \RuntimeException('Effect has no configured workflow.');
        }

        $revisionId = $this->resolveRevisionId($effect, $publishedRevisionId, $actorUserId);
        if (!$revisionId) {
            throw new \RuntimeException('Published revision is required.');
        }

        $environmentId = $this->resolveProductionEnvironmentId($workflowId, $prodExecutionEnvironmentId);
        if (!$environmentId) {
            throw new \RuntimeException('Effect is not available for production processing.');
        }

        $effect->published_revision_id = $revisionId;
        $effect->prod_execution_environment_id = $environmentId;
        $effect->publication_status = 'published';
        $effect->save();

        return $effect->fresh();
    }

    public function resolveProductionEnvironmentForEffect(
        Effect $effect,
        ?int $workflowIdOverride = null
    ): ?ExecutionEnvironment
    {
        $workflowId = $workflowIdOverride !== null
            ? (int) $workflowIdOverride
            : (int) ($effect->workflow_id ?? 0);
        if ($workflowId <= 0) {
            return null;
        }

        $id = $this->resolveProductionEnvironmentId(
            $workflowId,
            $effect->prod_execution_environment_id ? (int) $effect->prod_execution_environment_id : null
        );
        if (!$id) {
            return null;
        }

        return ExecutionEnvironment::query()->find($id);
    }

    private function resolveRevisionId(Effect $effect, ?int $revisionId, ?int $actorUserId): ?int
    {
        if ($revisionId) {
            $revision = EffectRevision::query()->where('effect_id', $effect->id)->find($revisionId);
            return $revision?->id;
        }

        if ($effect->published_revision_id) {
            $existing = EffectRevision::query()
                ->where('effect_id', $effect->id)
                ->find((int) $effect->published_revision_id);
            if ($existing) {
                return $existing->id;
            }
        }

        $latest = EffectRevision::query()
            ->where('effect_id', $effect->id)
            ->orderByDesc('id')
            ->first();
        if ($latest) {
            return $latest->id;
        }

        $created = app(EffectRevisionService::class)->createSnapshot($effect, $actorUserId);
        return $created->id;
    }

    private function resolveProductionEnvironmentId(int $workflowId, ?int $providedEnvironmentId): ?int
    {
        if ($providedEnvironmentId) {
            $provided = ExecutionEnvironment::query()->find($providedEnvironmentId);
            if ($provided && $this->environmentMatchesWorkflow($provided, $workflowId)) {
                return $provided->id;
            }
            return null;
        }

        $assignment = ComfyUiWorkflowFleet::query()
            ->where('workflow_id', $workflowId)
            ->where('stage', 'production')
            ->orderBy('id')
            ->first();
        if (!$assignment) {
            return null;
        }

        $fleet = ComfyUiGpuFleet::query()->find($assignment->fleet_id);
        if (!$fleet || !$fleet->slug) {
            return null;
        }

        $environment = ExecutionEnvironment::query()
            ->where('kind', 'prod_asg')
            ->where('stage', 'production')
            ->where('fleet_slug', $fleet->slug)
            ->first();

        if ($environment) {
            return $environment->id;
        }

        $created = ExecutionEnvironment::query()->create([
            'name' => 'Production ASG - ' . $fleet->slug,
            'kind' => 'prod_asg',
            'stage' => 'production',
            'fleet_slug' => $fleet->slug,
            'configuration_json' => [
                'instance_types' => $fleet->instance_types,
                'max_size' => $fleet->max_size,
                'warmup_seconds' => $fleet->warmup_seconds,
                'template_slug' => $fleet->template_slug,
            ],
            'is_active' => true,
        ]);

        return $created->id;
    }

    private function environmentMatchesWorkflow(ExecutionEnvironment $environment, int $workflowId): bool
    {
        if ($environment->kind !== 'prod_asg' || $environment->stage !== 'production' || !$environment->is_active) {
            return false;
        }
        if (!$environment->fleet_slug) {
            return false;
        }

        return ComfyUiWorkflowFleet::query()
            ->join('comfyui_gpu_fleets as fleets', 'fleets.id', '=', 'comfyui_workflow_fleets.fleet_id')
            ->where('comfyui_workflow_fleets.workflow_id', $workflowId)
            ->where('comfyui_workflow_fleets.stage', 'production')
            ->where('fleets.slug', $environment->fleet_slug)
            ->exists();
    }
}
