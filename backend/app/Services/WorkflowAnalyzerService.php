<?php

namespace App\Services;

use App\Models\OpenAI;

class WorkflowAnalyzerService
{
    public const PROMPT_VERSION = 'v1';
    public const SCHEMA_VERSION = 'v1';

    /**
     * @return array{
     *   properties: array<int, array<string, mixed>>,
     *   primary_input: array<string, mixed>|null,
     *   output: array<string, mixed>|null,
     *   placeholder_insertions: array<int, array<string, mixed>>,
     *   autoscaling_hints: array<string, mixed>|null
     * }
     */
    public function analyze(array $workflowJson, ?string $requestedOutputKind = null, ?string $exampleIoDescription = null): array
    {
        $sanitizedWorkflowJson = $this->redactSecrets($workflowJson);
        $prompt = $this->buildPrompt($sanitizedWorkflowJson, $requestedOutputKind, $exampleIoDescription);

        // Must go through the existing wrapper instead of direct SDK calls.
        $response = OpenAI::askChatGPT($prompt, true);
        if ($response === null) {
            throw new \RuntimeException('Workflow analysis failed: empty OpenAI response.');
        }

        $decoded = json_decode(json_encode($response), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Workflow analysis failed: invalid OpenAI JSON payload.');
        }

        $normalized = $this->normalizeProposal($decoded);

        return $this->enforceProposalContract($normalized);
    }

    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    private function buildPrompt(array $workflowJson, ?string $requestedOutputKind, ?string $exampleIoDescription): string
    {
        $workflow = json_encode($workflowJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $outputKind = $requestedOutputKind ?: 'unknown';
        $exampleDescription = trim((string) $exampleIoDescription);

        $exampleSection = $exampleDescription !== ''
            ? "EXAMPLE_IO_DESCRIPTION:\n{$exampleDescription}\n"
            : "EXAMPLE_IO_DESCRIPTION:\n(none)\n";

        return <<<PROMPT
You are a Workflow Analyzer for ComfyUI integrations.
Return ONLY one JSON object. No markdown.

SCHEMA_VERSION: {$this->schemaVersion()}
REQUESTED_OUTPUT_KIND: {$outputKind}
{$exampleSection}
Given a ComfyUI workflow JSON, propose integration metadata for an app:
1) properties[] for user-configurable and required placeholders
2) primary_input node/key/type
3) output node id + mime/extension
4) placeholder_insertions[] with JSON pointer and suggested token
5) autoscaling_hints: workload_kind, work_units_property_key, slo candidates

Constraints:
- property type must be one of: text, image, audio, video
- workload_kind must be one of: image, video
- Use null for unknown values.
- Keep the output compact and machine-parseable.

Expected JSON shape:
{
  "properties": [{"key":"", "name":"", "type":"text|image|audio|video", "required":true, "placeholder":"", "user_configurable":true}],
  "primary_input": {"node_id":"", "key":"", "type":"image|video|audio|text"},
  "output": {"node_id":"", "mime_type":"", "extension":""},
  "placeholder_insertions": [{"json_pointer":"", "placeholder":"", "reason":""}],
  "autoscaling_hints": {"workload_kind":"image|video", "work_units_property_key":null, "slo_p95_wait_seconds":null, "slo_video_seconds_per_processing_second_p95":null}
}

WORKFLOW_JSON:
{$workflow}
PROMPT;
    }

    private function normalizeProposal(array $payload): array
    {
        $normalizedProperties = [];
        $seenPropertyKeys = [];
        $properties = $payload['properties'] ?? [];
        if (is_array($properties)) {
            foreach ($properties as $property) {
                if (!is_array($property)) {
                    continue;
                }
                $type = strtolower((string) ($property['type'] ?? 'text'));
                if (!in_array($type, ['text', 'image', 'audio', 'video'], true)) {
                    $type = 'text';
                }
                $key = trim((string) ($property['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                if (isset($seenPropertyKeys[$key])) {
                    continue;
                }
                $seenPropertyKeys[$key] = true;
                $normalizedProperties[] = [
                    'key' => $key,
                    'name' => trim((string) ($property['name'] ?? $key)),
                    'type' => $type,
                    'required' => (bool) ($property['required'] ?? false),
                    'placeholder' => $this->nullableString($property['placeholder'] ?? null),
                    'user_configurable' => (bool) ($property['user_configurable'] ?? true),
                ];
            }
        }

        $primaryInput = null;
        if (isset($payload['primary_input']) && is_array($payload['primary_input'])) {
            $primaryType = strtolower((string) ($payload['primary_input']['type'] ?? ''));
            $primaryInput = [
                'node_id' => $this->nullableString($payload['primary_input']['node_id'] ?? null),
                'key' => $this->nullableString($payload['primary_input']['key'] ?? null),
                'type' => in_array($primaryType, ['text', 'image', 'audio', 'video'], true) ? $primaryType : null,
            ];
        }

        $output = null;
        if (isset($payload['output']) && is_array($payload['output'])) {
            $output = [
                'node_id' => $this->nullableString($payload['output']['node_id'] ?? null),
                'mime_type' => $this->nullableString($payload['output']['mime_type'] ?? null),
                'extension' => $this->nullableString($payload['output']['extension'] ?? null),
            ];
        }

        $placeholderInsertions = [];
        $insertions = $payload['placeholder_insertions'] ?? [];
        if (is_array($insertions)) {
            foreach ($insertions as $insertion) {
                if (!is_array($insertion)) {
                    continue;
                }
                $jsonPointer = trim((string) ($insertion['json_pointer'] ?? ''));
                $placeholder = trim((string) ($insertion['placeholder'] ?? ''));
                if ($jsonPointer === '' || $placeholder === '') {
                    continue;
                }
                $placeholderInsertions[] = [
                    'json_pointer' => $jsonPointer,
                    'placeholder' => $placeholder,
                    'reason' => $this->nullableString($insertion['reason'] ?? null),
                ];
            }
        }

        $autoscalingHints = null;
        if (isset($payload['autoscaling_hints']) && is_array($payload['autoscaling_hints'])) {
            $workloadKind = strtolower((string) ($payload['autoscaling_hints']['workload_kind'] ?? ''));
            $autoscalingHints = [
                'workload_kind' => in_array($workloadKind, ['image', 'video'], true) ? $workloadKind : null,
                'work_units_property_key' => $this->nullableString($payload['autoscaling_hints']['work_units_property_key'] ?? null),
                'slo_p95_wait_seconds' => $this->nullableNumeric($payload['autoscaling_hints']['slo_p95_wait_seconds'] ?? null),
                'slo_video_seconds_per_processing_second_p95' => $this->nullableNumeric($payload['autoscaling_hints']['slo_video_seconds_per_processing_second_p95'] ?? null),
            ];
        }

        return [
            'properties' => $normalizedProperties,
            'primary_input' => $primaryInput,
            'output' => $output,
            'placeholder_insertions' => $placeholderInsertions,
            'autoscaling_hints' => $autoscalingHints,
        ];
    }

    private function enforceProposalContract(array $normalized): array
    {
        $requiredKeys = ['properties', 'primary_input', 'output', 'placeholder_insertions', 'autoscaling_hints'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $normalized)) {
                throw new \RuntimeException("Workflow analysis failed: missing required key '{$key}'.");
            }
        }

        if (!is_array($normalized['properties']) || !is_array($normalized['placeholder_insertions'])) {
            throw new \RuntimeException('Workflow analysis failed: invalid collection types.');
        }

        if ($normalized['primary_input'] !== null && !is_array($normalized['primary_input'])) {
            throw new \RuntimeException('Workflow analysis failed: primary_input must be null or object.');
        }
        if ($normalized['output'] !== null && !is_array($normalized['output'])) {
            throw new \RuntimeException('Workflow analysis failed: output must be null or object.');
        }
        if ($normalized['autoscaling_hints'] !== null && !is_array($normalized['autoscaling_hints'])) {
            throw new \RuntimeException('Workflow analysis failed: autoscaling_hints must be null or object.');
        }

        return $normalized;
    }

    private function redactSecrets(array $workflowJson): array
    {
        array_walk_recursive($workflowJson, function (&$value): void {
            if (!is_string($value)) {
                return;
            }

            if (preg_match('/(api[_-]?key|secret|token|password)/i', $value)) {
                $value = '[REDACTED]';
                return;
            }

            $value = preg_replace('/https?:\/\/[^\s"\']+/i', '[REDACTED_URL]', $value) ?: $value;
        });

        return $workflowJson;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableNumeric(mixed $value): float|int|null
    {
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
