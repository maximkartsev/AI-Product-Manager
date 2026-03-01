<?php

namespace App\Services\Quality;

use Illuminate\Support\Facades\Http;

class GeminiQualityEvaluationProvider implements QualityEvaluationProviderInterface
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function evaluate(array $request): array
    {
        $apiKey = (string) config('services.comfyui.gemini_api_key');
        $model = (string) config('services.comfyui.gemini_model', 'gemini-2.0-flash');
        $rubricVersion = (string) ($request['rubric_version'] ?? 'v1');

        $inputRef = (string) ($request['input_ref'] ?? '');
        $outputRef = (string) ($request['output_ref'] ?? '');
        $prompt = $this->buildPrompt($inputRef, $outputRef, $rubricVersion);

        if ($apiKey === '') {
            return $this->fallback($model, 'gemini_api_key_missing');
        }

        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model
        );

        try {
            $response = Http::timeout(40)
                ->acceptJson()
                ->post($endpoint . '?key=' . urlencode($apiKey), [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [[
                            'text' => $prompt,
                        ]],
                    ]],
                ]);
            if (!$response->successful()) {
                return $this->fallback($model, 'gemini_http_error', [
                    'status' => $response->status(),
                ]);
            }

            $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
            $parsed = $this->extractJson($text);
            if (!is_array($parsed)) {
                return $this->fallback($model, 'gemini_invalid_json');
            }

            $vector = $this->normalizeVector($parsed);

            return [
                'provider' => 'gemini',
                'model' => $model,
                'composite_score' => $this->compositeScore($vector),
                'vector' => $vector,
                'raw' => [
                    'text' => $text,
                    'json' => $parsed,
                ],
            ];
        } catch (\Throwable $e) {
            return $this->fallback($model, 'gemini_exception', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $vector
     */
    private function compositeScore(array $vector): float
    {
        $scores = [
            (float) ($vector['fidelity_to_input'] ?? 0.0),
            (float) ($vector['artifacts'] ?? 0.0),
            (float) ($vector['temporal_stability'] ?? 0.0),
            (float) ($vector['prompt_adherence'] ?? 0.0),
        ];

        return round(array_sum($scores) / max(1, count($scores)), 4);
    }

    private function buildPrompt(string $inputRef, string $outputRef, string $rubricVersion): string
    {
        return <<<PROMPT
You are a strict quality evaluator for AI video effects.
Return ONLY JSON.

Rubric version: {$rubricVersion}

Input reference: {$inputRef}
Output reference: {$outputRef}

Return this exact JSON shape with values in range [0, 1]:
{
  "fidelity_to_input": 0.0,
  "artifacts": 0.0,
  "temporal_stability": 0.0,
  "prompt_adherence": 0.0,
  "explanation": "short explanation"
}
PROMPT;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function normalizeVector(array $parsed): array
    {
        return [
            'fidelity_to_input' => $this->clamp01($parsed['fidelity_to_input'] ?? null),
            'artifacts' => $this->clamp01($parsed['artifacts'] ?? null),
            'temporal_stability' => $this->clamp01($parsed['temporal_stability'] ?? null),
            'prompt_adherence' => $this->clamp01($parsed['prompt_adherence'] ?? null),
            'explanation' => is_string($parsed['explanation'] ?? null)
                ? trim((string) $parsed['explanation'])
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function fallback(string $model, string $reason, array $meta = []): array
    {
        $vector = [
            'fidelity_to_input' => 0.5,
            'artifacts' => 0.5,
            'temporal_stability' => 0.5,
            'prompt_adherence' => 0.5,
            'explanation' => 'Fallback score used because Gemini provider was unavailable.',
        ];

        return [
            'provider' => 'gemini',
            'model' => $model,
            'composite_score' => $this->compositeScore($vector),
            'vector' => $vector,
            'raw' => [
                'fallback_reason' => $reason,
                'meta' => $meta,
            ],
        ];
    }

    private function clamp01(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $trimmed, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode((string) $matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }
}

