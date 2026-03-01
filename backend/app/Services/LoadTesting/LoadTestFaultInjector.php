<?php

namespace App\Services\LoadTesting;

use App\Models\LoadTestRun;
use App\Models\LoadTestStage;
use Illuminate\Support\Str;

class LoadTestFaultInjector
{
    /**
     * @return array<string, mixed>
     */
    public function injectForStage(LoadTestRun $run, LoadTestStage $stage): array
    {
        if (!$stage->fault_enabled) {
            return [
                'status' => 'skipped',
                'reason' => 'fault_disabled',
            ];
        }

        if (($stage->fault_method ?? 'fis') !== 'fis') {
            return [
                'status' => 'skipped',
                'reason' => 'unsupported_fault_method',
                'fault_method' => $stage->fault_method,
            ];
        }

        $config = is_array($stage->config_json) ? $stage->config_json : [];
        $templateId = (string) ($config['fis_experiment_template_id'] ?? env('AWS_FIS_EXPERIMENT_TEMPLATE_ID', ''));
        if ($templateId === '') {
            return [
                'status' => 'skipped',
                'reason' => 'fis_template_not_configured',
            ];
        }

        try {
            $client = new \Aws\Fis\FisClient([
                'region' => (string) config('services.comfyui.aws_region', env('AWS_DEFAULT_REGION', 'us-east-1')),
                'version' => 'latest',
            ]);

            $result = $client->startExperiment([
                'experimentTemplateId' => $templateId,
                'clientToken' => 'studio-load-test-' . $run->id . '-' . $stage->id . '-' . Str::uuid()->toString(),
                'tags' => [
                    'source' => 'studio_load_test',
                    'load_test_run_id' => (string) $run->id,
                    'load_test_stage_id' => (string) $stage->id,
                ],
            ]);

            $experiment = $result->get('experiment') ?? [];

            return [
                'status' => 'started',
                'fault_method' => 'fis',
                'template_id' => $templateId,
                'experiment_id' => data_get($experiment, 'id'),
                'experiment_arn' => data_get($experiment, 'arn'),
                'state' => data_get($experiment, 'state.status'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'fault_method' => 'fis',
                'template_id' => $templateId,
                'message' => $e->getMessage(),
            ];
        }
    }
}

