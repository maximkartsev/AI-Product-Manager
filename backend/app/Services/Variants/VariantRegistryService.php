<?php

namespace App\Services\Variants;

use App\Models\ComfyUiWorkflowFleet;
use App\Models\EffectRevision;
use App\Models\ExecutionEnvironment;
use App\Models\ExperimentVariant;

class VariantRegistryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function eligibleVariantsForEffectRevision(int $effectRevisionId, string $stage = 'staging'): array
    {
        $revision = EffectRevision::query()->find($effectRevisionId);
        if (!$revision) {
            throw new \RuntimeException('Effect revision not found.');
        }

        $workflowId = (int) ($revision->workflow_id ?? 0);
        if ($workflowId <= 0) {
            throw new \RuntimeException('Effect revision has no workflow binding.');
        }

        $targetStage = $this->normalizeStage($stage);
        $targetKind = $targetStage === 'production' ? 'prod_asg' : 'test_asg';
        $environments = $this->resolveEnvironments($revision, $targetStage, $targetKind);
        $experimentVariants = ExperimentVariant::query()
            ->where('is_active', true)
            ->where('target_environment_kind', $targetKind)
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($environments as $environment) {
            if (!$this->workflowEligibleForEnvironment($workflowId, $targetStage, $environment->fleet_slug)) {
                continue;
            }

            if ($experimentVariants->isEmpty()) {
                $rows[] = $this->payload(
                    $effectRevisionId,
                    $workflowId,
                    $environment->id,
                    $targetStage,
                    null,
                    null,
                    null
                );
                continue;
            }

            foreach ($experimentVariants as $experimentVariant) {
                $rows[] = $this->payload(
                    $effectRevisionId,
                    $workflowId,
                    $environment->id,
                    $targetStage,
                    $experimentVariant->id,
                    $experimentVariant->fleet_config_intent_json,
                    $experimentVariant->constraints_json
                );
            }
        }

        return $rows;
    }

    public function buildVariantId(
        int $effectRevisionId,
        int $workflowId,
        int $executionEnvironmentId,
        string $stage,
        ?int $experimentVariantId
    ): string {
        return implode(':', [
            'er', $effectRevisionId,
            'wf', $workflowId,
            'env', $executionEnvironmentId,
            'stage', $stage,
            'exp', $experimentVariantId ?? 0,
        ]);
    }

    /**
     * @param array<string, mixed>|null $fleetConfigIntent
     * @param array<string, mixed>|null $constraints
     * @return array<string, mixed>
     */
    private function payload(
        int $effectRevisionId,
        int $workflowId,
        int $executionEnvironmentId,
        string $stage,
        ?int $experimentVariantId,
        ?array $fleetConfigIntent,
        ?array $constraints
    ): array {
        return [
            'variant_id' => $this->buildVariantId(
                $effectRevisionId,
                $workflowId,
                $executionEnvironmentId,
                $stage,
                $experimentVariantId
            ),
            'effect_revision_id' => $effectRevisionId,
            'workflow_id' => $workflowId,
            'execution_environment_id' => $executionEnvironmentId,
            'stage' => $stage,
            'experiment_variant_id' => $experimentVariantId,
            'fleet_config_intent_json' => $fleetConfigIntent,
            'constraints_json' => $constraints,
        ];
    }

    private function normalizeStage(string $stage): string
    {
        $normalized = strtolower(trim($stage));
        if ($normalized === 'production') {
            return 'production';
        }

        return 'staging';
    }

    /**
     * @return array<int, ExecutionEnvironment>
     */
    private function resolveEnvironments(EffectRevision $revision, string $stage, string $kind): array
    {
        $recommendedEnvironmentId = (int) ($revision->recommended_execution_environment_id ?? 0);
        if ($recommendedEnvironmentId > 0) {
            $recommended = ExecutionEnvironment::query()
                ->where('id', $recommendedEnvironmentId)
                ->where('is_active', true)
                ->where('stage', $stage)
                ->where('kind', $kind)
                ->first();
            if ($recommended) {
                return [$recommended];
            }
        }

        return ExecutionEnvironment::query()
            ->where('is_active', true)
            ->where('stage', $stage)
            ->where('kind', $kind)
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function workflowEligibleForEnvironment(int $workflowId, string $stage, ?string $fleetSlug): bool
    {
        if (!$fleetSlug || trim($fleetSlug) === '') {
            return true;
        }

        return ComfyUiWorkflowFleet::query()
            ->where('workflow_id', $workflowId)
            ->where('stage', $stage)
            ->whereHas('fleet', function ($query) use ($fleetSlug, $stage) {
                $query->where('slug', $fleetSlug)
                    ->where('stage', $stage);
            })
            ->exists();
    }
}

