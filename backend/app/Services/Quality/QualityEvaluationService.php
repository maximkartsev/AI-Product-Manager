<?php

namespace App\Services\Quality;

use App\Models\QualityEvaluation;

class QualityEvaluationService
{
    public function __construct(
        private readonly GeminiQualityEvaluationProvider $geminiProvider
    ) {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function evaluateAndStore(array $request): QualityEvaluation
    {
        $providerName = strtolower((string) ($request['provider'] ?? config('services.comfyui.quality_eval_provider', 'gemini')));
        $provider = $this->resolveProvider($providerName);
        $result = $provider->evaluate($request);

        return QualityEvaluation::query()->create([
            'benchmark_matrix_run_id' => $request['benchmark_matrix_run_id'] ?? null,
            'benchmark_matrix_run_item_id' => $request['benchmark_matrix_run_item_id'] ?? null,
            'effect_test_run_id' => $request['effect_test_run_id'] ?? null,
            'benchmark_context_id' => $request['benchmark_context_id'] ?? null,
            'rubric_version' => (string) ($request['rubric_version'] ?? 'v1'),
            'provider' => (string) ($result['provider'] ?? $providerName),
            'model' => $result['model'] ?? null,
            'status' => 'completed',
            'composite_score' => $result['composite_score'] ?? null,
            'vector_json' => $result['vector'] ?? null,
            'request_json' => $request,
            'result_json' => $result['raw'] ?? null,
            'evaluated_at' => now(),
        ]);
    }

    private function resolveProvider(string $providerName): QualityEvaluationProviderInterface
    {
        return match ($providerName) {
            'gemini' => $this->geminiProvider,
            default => $this->geminiProvider,
        };
    }
}

