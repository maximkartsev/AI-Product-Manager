<?php

namespace App\Services\Variants;

use App\Models\BenchmarkMatrixRun;
use App\Models\BenchmarkMatrixRunItem;
use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\RunArtifact;
use App\Models\User;
use App\Services\StudioBlackboxRunnerService;

class BenchmarkMatrixRunnerService
{
    public function __construct(
        private readonly VariantRegistryService $variantRegistryService,
        private readonly StudioBlackboxRunnerService $blackboxRunnerService
    ) {
    }

    /**
     * @param array<string, mixed> $inputPayload
     * @param array<string, mixed> $costModelInput
     */
    public function run(
        BenchmarkMatrixRun $matrixRun,
        int $inputFileId,
        array $inputPayload,
        int $runsPerVariant = 1,
        array $costModelInput = []
    ): BenchmarkMatrixRun {
        $revision = EffectRevision::query()->find((int) $matrixRun->effect_revision_id);
        if (!$revision) {
            throw new \RuntimeException('Effect revision for benchmark matrix not found.');
        }
        $effect = Effect::query()->find((int) $revision->effect_id);
        if (!$effect) {
            throw new \RuntimeException('Effect for benchmark matrix not found.');
        }

        $user = $this->resolveUser($matrixRun);
        if (!$user) {
            throw new \RuntimeException('Unable to resolve benchmark execution user.');
        }

        $variants = $this->variantRegistryService->eligibleVariantsForEffectRevision(
            (int) $matrixRun->effect_revision_id,
            (string) $matrixRun->stage
        );
        if (empty($variants)) {
            throw new \RuntimeException('No eligible variants found for benchmark matrix.');
        }

        $matrixRun->status = 'running';
        $matrixRun->variant_count = count($variants);
        $matrixRun->runs_per_variant = max(1, $runsPerVariant);
        $matrixRun->started_at = $matrixRun->started_at ?: now();
        $matrixRun->save();

        $submittedItems = 0;
        $failedItems = 0;
        foreach ($variants as $variant) {
            $environment = ExecutionEnvironment::query()->find((int) ($variant['execution_environment_id'] ?? 0));
            if (!$environment) {
                $failedItems++;
                continue;
            }

            $effectTestRun = EffectTestRun::query()->create([
                'effect_id' => $effect->id,
                'effect_revision_id' => $revision->id,
                'execution_environment_id' => $environment->id,
                'run_mode' => 'blackbox',
                'target_count' => max(1, $runsPerVariant),
                'status' => 'queued',
                'created_by_user_id' => $matrixRun->created_by_user_id,
            ]);

            try {
                $result = $this->blackboxRunnerService->run(
                    run: $effectTestRun,
                    effect: $effect,
                    revision: $revision,
                    environment: $environment,
                    user: $user,
                    inputFileId: $inputFileId,
                    inputPayload: $inputPayload,
                    count: max(1, $runsPerVariant),
                    costModelInput: $costModelInput,
                    dispatchContext: [
                        'dispatch_stage' => (string) ($variant['stage'] ?? 'staging'),
                        'benchmark_context_id' => (string) $matrixRun->benchmark_context_id,
                        'experiment_variant_id' => $variant['experiment_variant_id'] ?? null,
                    ]
                );
                $itemStatus = 'queued';
                $submittedItems++;
            } catch (\Throwable $e) {
                $failedItems++;
                $failedRun = $this->blackboxRunnerService->markFailed($effectTestRun, $e->getMessage());
                $result = [
                    'run' => $failedRun,
                    'dispatch_count' => 0,
                    'dispatch_ids' => [],
                    'job_ids' => [],
                    'error' => $e->getMessage(),
                ];
                $itemStatus = 'failed';
            }

            $item = BenchmarkMatrixRunItem::query()->create([
                'benchmark_matrix_run_id' => $matrixRun->id,
                'variant_id' => (string) ($variant['variant_id'] ?? ''),
                'execution_environment_id' => $environment->id,
                'experiment_variant_id' => $variant['experiment_variant_id'] ?? null,
                'effect_test_run_id' => data_get($result, 'run.id'),
                'dispatch_count' => (int) ($result['dispatch_count'] ?? 0),
                'status' => $itemStatus,
                'metrics_json' => [
                    'variant' => $variant,
                    'dispatch_ids' => $result['dispatch_ids'] ?? [],
                    'job_ids' => $result['job_ids'] ?? [],
                    'cost_report' => $result['cost_report'] ?? null,
                    'error' => $result['error'] ?? null,
                ],
            ]);

            RunArtifact::query()->create([
                'effect_test_run_id' => $item->effect_test_run_id,
                'artifact_type' => 'benchmark_variant_dispatches',
                'storage_disk' => null,
                'storage_path' => null,
                'metadata_json' => [
                    'benchmark_matrix_run_id' => $matrixRun->id,
                    'benchmark_context_id' => $matrixRun->benchmark_context_id,
                    'benchmark_matrix_run_item_id' => $item->id,
                    'variant' => $variant,
                    'dispatch_ids' => $result['dispatch_ids'] ?? [],
                    'job_ids' => $result['job_ids'] ?? [],
                ],
            ]);
        }

        $matrixRun->status = $failedItems > 0 ? ($submittedItems > 0 ? 'partial_failed' : 'failed') : 'queued';
        $matrixRun->completed_at = now();
        $matrixRun->metrics_json = array_merge($matrixRun->metrics_json ?? [], [
            'submitted_items' => $submittedItems,
            'failed_items' => $failedItems,
            'variant_count' => count($variants),
            'runs_per_variant' => max(1, $runsPerVariant),
        ]);
        $matrixRun->save();

        return $matrixRun->fresh('items');
    }

    private function resolveUser(BenchmarkMatrixRun $matrixRun): ?User
    {
        $createdByUserId = (int) ($matrixRun->created_by_user_id ?? 0);
        if ($createdByUserId > 0) {
            $user = User::query()->find($createdByUserId);
            if ($user) {
                return $user;
            }
        }

        return User::query()->where('is_admin', true)->orderBy('id')->first();
    }
}

