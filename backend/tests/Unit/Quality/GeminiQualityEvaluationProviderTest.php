<?php

namespace Tests\Unit\Quality;

use App\Services\Quality\GeminiQualityEvaluationProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiQualityEvaluationProviderTest extends TestCase
{
    public function test_it_returns_fallback_scores_when_api_key_missing(): void
    {
        Config::set('services.comfyui.gemini_api_key', null);
        $provider = new GeminiQualityEvaluationProvider();

        $result = $provider->evaluate([
            'input_ref' => 's3://input.mp4',
            'output_ref' => 's3://output.mp4',
            'rubric_version' => 'v1',
        ]);

        $this->assertSame('gemini', $result['provider']);
        $this->assertIsArray($result['vector']);
        $this->assertEqualsWithDelta(0.5, (float) $result['composite_score'], 0.0001);
        $this->assertSame('gemini_api_key_missing', data_get($result, 'raw.fallback_reason'));
    }

    public function test_it_parses_json_response_from_gemini(): void
    {
        Config::set('services.comfyui.gemini_api_key', 'test-key');
        Config::set('services.comfyui.gemini_model', 'gemini-test-model');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'fidelity_to_input' => 0.9,
                                'artifacts' => 0.8,
                                'temporal_stability' => 0.7,
                                'prompt_adherence' => 0.6,
                                'explanation' => 'Looks good',
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $provider = new GeminiQualityEvaluationProvider();
        $result = $provider->evaluate([
            'input_ref' => 's3://input.mp4',
            'output_ref' => 's3://output.mp4',
            'rubric_version' => 'v1',
        ]);

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame('gemini-test-model', $result['model']);
        $this->assertEqualsWithDelta(0.75, (float) $result['composite_score'], 0.0001);
        $this->assertSame(0.9, data_get($result, 'vector.fidelity_to_input'));
        $this->assertSame('Looks good', data_get($result, 'vector.explanation'));
    }

    public function test_golden_dataset_cases_return_valid_score_range(): void
    {
        Config::set('services.comfyui.gemini_api_key', null);
        $provider = new GeminiQualityEvaluationProvider();
        $datasetPath = base_path('tests/Fixtures/quality/golden_dataset.json');
        $dataset = json_decode((string) file_get_contents($datasetPath), true);

        $this->assertIsArray($dataset);
        foreach ($dataset as $case) {
            $result = $provider->evaluate([
                'input_ref' => $case['input_ref'],
                'output_ref' => $case['output_ref'],
                'rubric_version' => $case['rubric_version'],
            ]);

            $this->assertGreaterThanOrEqual((float) $case['expected_min_composite'], (float) $result['composite_score']);
            $this->assertLessThanOrEqual((float) $case['expected_max_composite'], (float) $result['composite_score']);
            $this->assertIsArray($result['vector']);
        }
    }
}

