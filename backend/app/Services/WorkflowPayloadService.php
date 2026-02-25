<?php

namespace App\Services;

use App\Models\Effect;
use App\Models\File;
use App\Models\Workflow;
use Illuminate\Support\Facades\Storage;

class WorkflowPayloadService
{
    /**
     * Resolve property values by layering: workflow defaults → effect overrides → user input.
     *
     * @return array<string, mixed> key → resolved value
     */
    public function resolveProperties(Workflow $workflow, Effect $effect, array $userInput = []): array
    {
        $properties = $workflow->properties ?? [];
        $overrides = $effect->property_overrides ?? [];
        $resolved = [];

        foreach ($properties as $prop) {
            $key = $prop['key'] ?? null;
            if (!$key) {
                continue;
            }

            // Skip primary input — handled separately via input_file
            if (!empty($prop['is_primary_input'])) {
                continue;
            }

            // Layer: default → effect override → user input (only if user_configurable)
            $value = $prop['default_value'] ?? null;

            if (array_key_exists($key, $overrides)) {
                $value = $overrides[$key];
            }

            if (!empty($prop['user_configurable']) && array_key_exists($key, $userInput)) {
                $value = $userInput[$key];
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * Compute normalized work units for autoscaling.
     *
     * @return array{units: float, kind: string}
     */
    public function computeWorkUnits(Workflow $workflow, Effect $effect, array $userInput = []): array
    {
        $resolvedProps = $this->resolveProperties($workflow, $effect, $userInput);
        return $this->computeWorkUnitsFromResolvedProps($workflow, $resolvedProps);
    }

    /**
     * Compute work units using already-resolved properties.
     *
     * @param array<string, mixed> $resolvedProps
     * @return array{units: float, kind: string}
     */
    public function computeWorkUnitsFromResolvedProps(Workflow $workflow, array $resolvedProps): array
    {
        $workloadKind = $this->inferWorkloadKind($workflow);
        if ($workloadKind !== 'video') {
            return ['units' => 1.0, 'kind' => 'image_job'];
        }

        $units = null;
        $key = $workflow->work_units_property_key;
        if ($key && array_key_exists($key, $resolvedProps)) {
            $units = $resolvedProps[$key];
        }

        return [
            'units' => $this->normalizeWorkUnits($units),
            'kind' => 'video_seconds',
        ];
    }

    /**
     * Build the complete input_payload for a job dispatch.
     */
    public function buildJobPayload(Effect $effect, array $resolvedProps, ?File $inputFile): array
    {
        $workflow = $effect->workflow;
        if (!$workflow) {
            throw new \RuntimeException('Effect is not linked to a workflow.');
        }

        $workflowJson = $this->loadWorkflowJson($workflow);
        $properties = $workflow->properties ?? [];
        $assets = [];

        foreach ($properties as $prop) {
            $key = $prop['key'] ?? null;
            $type = $prop['type'] ?? 'text';
            $placeholder = $prop['placeholder'] ?? null;

            if (!$key || !$placeholder) {
                continue;
            }

            if (!empty($prop['is_primary_input'])) {
                // Primary input — value comes from input file
                if ($inputFile) {
                    $assets[] = [
                        'key' => $key,
                        'placeholder' => $placeholder,
                        's3_path' => $inputFile->path,
                        's3_disk' => $inputFile->disk,
                        'content_hash' => null,
                        'type' => $type,
                        'is_primary_input' => true,
                    ];
                }
                continue;
            }

            $value = $resolvedProps[$key] ?? null;

            if ($type === 'text') {
                // Text properties: replace placeholder in workflow JSON
                $workflowJson = $this->replacePlaceholderInValue(
                    $workflowJson,
                    $placeholder,
                    (string) ($value ?? '')
                );
            } elseif (in_array($type, ['image', 'video'], true) && $value) {
                // Asset properties: add to assets array for worker to download
                $assetPath = null;
                $assetDisk = config('filesystems.default');
                if (is_array($value)) {
                    $assetPath = $value['path'] ?? null;
                    $assetDisk = $value['disk'] ?? $assetDisk;
                } elseif (is_string($value)) {
                    $assetPath = $value;
                }
                if ($assetPath) {
                    $assets[] = [
                        'key' => $key,
                        'placeholder' => $placeholder,
                        's3_path' => $assetPath,
                        's3_disk' => $assetDisk,
                        'content_hash' => $prop['default_value_hash'] ?? null,
                        'type' => $type,
                        'is_primary_input' => false,
                    ];
                }
            }
        }

        $inputPayload = [
            'workflow' => $workflowJson,
            'assets' => $assets,
            'output_node_id' => $workflow->output_node_id,
            'output_extension' => $workflow->output_extension ?: 'mp4',
            'output_mime_type' => $workflow->output_mime_type ?: 'video/mp4',
        ];

        if ($inputFile) {
            $inputPayload['input_path_placeholder'] = $this->getPrimaryInputPlaceholder($properties);
            if ($inputFile->original_filename) {
                $inputPayload['input_name'] = $inputFile->original_filename;
            }
            if ($inputFile->mime_type) {
                $inputPayload['input_mime_type'] = $inputFile->mime_type;
            }
        }

        return $inputPayload;
    }

    private function loadWorkflowJson(Workflow $workflow): array
    {
        $path = (string) ($workflow->comfyui_workflow_path ?? '');
        if ($path === '') {
            throw new \RuntimeException('Workflow has no workflow JSON path configured.');
        }

        $disk = (string) config('services.comfyui.workflow_disk', 's3');

        try {
            if (!Storage::disk($disk)->exists($path)) {
                throw new \RuntimeException('Workflow file not found.');
            }
            $raw = Storage::disk($disk)->get($path);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Workflow file not found.');
        }

        $json = json_decode($raw ?: '', true);
        if (!is_array($json) || empty($json)) {
            throw new \RuntimeException('Workflow JSON is invalid or empty.');
        }

        return $json;
    }

    private function replacePlaceholderInValue(mixed $value, string $placeholder, string $replacement): mixed
    {
        if (is_string($value)) {
            return str_replace($placeholder, $replacement, $value);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->replacePlaceholderInValue($v, $placeholder, $replacement);
            }
        }

        return $value;
    }

    private function inferWorkloadKind(Workflow $workflow): string
    {
        $kind = strtolower((string) ($workflow->workload_kind ?? ''));
        if (in_array($kind, ['image', 'video'], true)) {
            return $kind;
        }
        $mimeType = strtolower((string) ($workflow->output_mime_type ?? ''));
        return str_starts_with($mimeType, 'video/') ? 'video' : 'image';
    }

    private function normalizeWorkUnits(mixed $value): float
    {
        if (is_numeric($value)) {
            $units = (float) $value;
            if ($units > 0) {
                return $units;
            }
        }

        return 1.0;
    }

    private function getPrimaryInputPlaceholder(array $properties): string
    {
        foreach ($properties as $prop) {
            if (!empty($prop['is_primary_input'])) {
                return $prop['placeholder'] ?? '__INPUT_PATH__';
            }
        }
        return '__INPUT_PATH__';
    }
}
