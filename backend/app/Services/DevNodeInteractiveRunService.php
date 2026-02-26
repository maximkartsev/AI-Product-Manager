<?php

namespace App\Services;

use App\Models\Effect;
use App\Models\EffectRevision;
use App\Models\EffectTestRun;
use App\Models\ExecutionEnvironment;
use App\Models\File;
use App\Models\RunArtifact;
use App\Models\Workflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DevNodeInteractiveRunService
{
    public function __construct(
        private readonly WorkflowPayloadService $workflowPayloadService
    ) {
    }

    /**
     * @return array{run: EffectTestRun, artifacts: array<int, RunArtifact>}
     */
    public function execute(
        EffectTestRun $run,
        EffectRevision $revision,
        ExecutionEnvironment $environment,
        array $inputPayload
    ): array {
        $startedAt = microtime(true);
        $endpoint = $this->resolveEndpoint($environment);

        $this->markRunning($run);

        $effect = Effect::query()->find($revision->effect_id);
        if (!$effect) {
            throw new \RuntimeException('Effect revision is not linked to a valid effect.');
        }

        $workflow = $this->resolveWorkflow($revision, $effect);
        $runtimeEffect = $this->buildRuntimeEffectFromRevision($effect, $workflow, $revision);

        $inputFile = $this->buildInputFileDescriptor($inputPayload);
        $resolvedProperties = $this->workflowPayloadService->resolveProperties(
            $workflow,
            $runtimeEffect,
            $this->normalizeUserProperties($inputPayload)
        );

        $jobPayload = $this->workflowPayloadService->buildJobPayload($runtimeEffect, $resolvedProperties, $inputFile);
        $placeholderMap = $this->uploadAssets($endpoint, $jobPayload['assets'] ?? []);
        $promptPayload = $this->applyPlaceholderMap($jobPayload['workflow'] ?? [], $placeholderMap);

        $promptResponse = Http::timeout(120)->post("{$endpoint}/prompt", [
            'prompt' => $promptPayload,
            'client_id' => 'studio-devnode-runner',
        ]);
        if (!$promptResponse->successful()) {
            throw new \RuntimeException('Failed to submit prompt to DevNode endpoint.');
        }

        $promptId = trim((string) ($promptResponse->json('prompt_id') ?? ''));
        if ($promptId === '') {
            throw new \RuntimeException('DevNode prompt response is missing prompt_id.');
        }

        $historyEntry = $this->pollHistoryUntilComplete($endpoint, $promptId);
        $outputFile = $this->extractOutputFile($historyEntry['outputs'] ?? [], (string) ($jobPayload['output_node_id'] ?? ''));

        $outputResponse = Http::timeout(120)->get(
            "{$endpoint}/view",
            [
                'filename' => $outputFile['filename'],
                'subfolder' => $outputFile['subfolder'],
                'type' => $outputFile['type'],
            ]
        );
        if (!$outputResponse->successful()) {
            throw new \RuntimeException('Failed to download output artifact from DevNode.');
        }

        $workflowDisk = (string) config('services.comfyui.workflow_disk', 's3');
        $outputExtension = ltrim((string) ($jobPayload['output_extension'] ?? 'bin'), '.');
        $outputPath = sprintf(
            'studio/effect-test-runs/%d/outputs/%s.%s',
            $run->id,
            (string) Str::uuid(),
            $outputExtension !== '' ? $outputExtension : 'bin'
        );
        Storage::disk($workflowDisk)->put($outputPath, $outputResponse->body());

        $artifacts = [];
        $artifacts[] = RunArtifact::query()->create([
            'effect_test_run_id' => $run->id,
            'artifact_type' => 'interactive_output',
            'storage_disk' => $workflowDisk,
            'storage_path' => $outputPath,
            'metadata_json' => [
                'prompt_id' => $promptId,
                'filename' => $outputFile['filename'] ?? null,
                'subfolder' => $outputFile['subfolder'] ?? null,
                'type' => $outputFile['type'] ?? null,
                'output_mime_type' => $jobPayload['output_mime_type'] ?? null,
            ],
        ]);

        $historyPath = sprintf('studio/effect-test-runs/%d/history/%s.json', $run->id, (string) Str::uuid());
        Storage::disk($workflowDisk)->put($historyPath, json_encode($historyEntry, JSON_PRETTY_PRINT));
        $artifacts[] = RunArtifact::query()->create([
            'effect_test_run_id' => $run->id,
            'artifact_type' => 'interactive_history',
            'storage_disk' => $workflowDisk,
            'storage_path' => $historyPath,
            'metadata_json' => [
                'prompt_id' => $promptId,
            ],
        ]);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $run->status = 'completed';
        $run->completed_at = now();
        $run->metrics_json = array_merge($run->metrics_json ?? [], [
            'prompt_id' => $promptId,
            'devnode_endpoint' => $endpoint,
            'duration_ms' => $durationMs,
        ]);
        $run->save();

        return [
            'run' => $run->fresh(),
            'artifacts' => $artifacts,
        ];
    }

    public function markFailed(EffectTestRun $run, string $message): EffectTestRun
    {
        $run->status = 'failed';
        if (!$run->started_at) {
            $run->started_at = now();
        }
        $run->completed_at = now();
        $run->metrics_json = array_merge($run->metrics_json ?? [], [
            'error' => $message,
        ]);
        $run->save();

        RunArtifact::query()->create([
            'effect_test_run_id' => $run->id,
            'artifact_type' => 'interactive_error',
            'metadata_json' => [
                'error' => $message,
            ],
        ]);

        return $run->fresh();
    }

    private function markRunning(EffectTestRun $run): void
    {
        $run->status = 'running';
        $run->started_at = now();
        $run->save();
    }

    private function resolveEndpoint(ExecutionEnvironment $environment): string
    {
        $devNode = $environment->relationLoaded('devNode')
            ? $environment->devNode
            : $environment->devNode()->first();

        if (!$devNode) {
            throw new \RuntimeException('Execution environment is not linked to a dev node.');
        }

        $endpoint = trim((string) ($devNode->public_endpoint ?: $devNode->private_endpoint));
        if ($endpoint === '') {
            throw new \RuntimeException('Dev node endpoint is missing.');
        }

        return rtrim($endpoint, '/');
    }

    private function resolveWorkflow(EffectRevision $revision, Effect $effect): Workflow
    {
        $workflowId = $revision->workflow_id ?: $effect->workflow_id;
        if (!$workflowId) {
            throw new \RuntimeException('Effect revision does not reference a workflow.');
        }

        $workflow = Workflow::query()->find($workflowId);
        if (!$workflow) {
            throw new \RuntimeException('Workflow for effect revision was not found.');
        }

        return $workflow;
    }

    private function buildRuntimeEffectFromRevision(Effect $effect, Workflow $workflow, EffectRevision $revision): Effect
    {
        $runtime = $effect->replicate();
        $runtime->id = $effect->id;
        $runtime->workflow_id = $workflow->id;
        $runtime->property_overrides = is_array($revision->property_overrides)
            ? $revision->property_overrides
            : ($effect->property_overrides ?? []);
        $runtime->setRelation('workflow', $workflow);

        return $runtime;
    }

    private function buildInputFileDescriptor(array $inputPayload): ?File
    {
        $path = trim((string) ($inputPayload['input_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $disk = trim((string) ($inputPayload['input_disk'] ?? config('services.comfyui.workflow_disk', 's3')));
        if ($disk === '') {
            $disk = (string) config('services.comfyui.workflow_disk', 's3');
        }

        if (!Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('Interactive input asset was not found.');
        }

        return new File([
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $inputPayload['input_name'] ?? basename($path),
            'mime_type' => $inputPayload['input_mime_type'] ?? 'application/octet-stream',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeUserProperties(array $inputPayload): array
    {
        $properties = $inputPayload['properties'] ?? [];
        return is_array($properties) ? $properties : [];
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<string, string>
     */
    private function uploadAssets(string $endpoint, array $assets): array
    {
        $placeholderMap = [];

        foreach ($assets as $asset) {
            $assetPath = trim((string) ($asset['s3_path'] ?? ''));
            $assetDisk = trim((string) ($asset['s3_disk'] ?? config('services.comfyui.workflow_disk', 's3')));
            if ($assetPath === '') {
                continue;
            }
            if (!Storage::disk($assetDisk)->exists($assetPath)) {
                throw new \RuntimeException("Asset not found: {$assetPath}");
            }

            $filename = basename($assetPath);
            $tempFile = tempnam(sys_get_temp_dir(), 'devnode_asset_');
            if ($tempFile === false) {
                throw new \RuntimeException('Failed to prepare temp file for asset upload.');
            }

            try {
                file_put_contents($tempFile, Storage::disk($assetDisk)->get($assetPath));
                $resource = fopen($tempFile, 'r');
                if ($resource === false) {
                    throw new \RuntimeException('Failed to open temp file for asset upload.');
                }

                try {
                    $uploadResponse = Http::timeout(120)
                        ->attach('image', $resource, $filename)
                        ->post("{$endpoint}/upload/image", [
                            'type' => 'input',
                            'overwrite' => 'true',
                        ]);
                } finally {
                    fclose($resource);
                }

                if (!$uploadResponse->successful()) {
                    throw new \RuntimeException("Asset upload failed for {$filename}.");
                }

                $uploadedName = trim((string) ($uploadResponse->json('name') ?? ''));
                if ($uploadedName === '') {
                    throw new \RuntimeException("Asset upload did not return a file name for {$filename}.");
                }

                $placeholder = trim((string) ($asset['placeholder'] ?? ''));
                if ($placeholder !== '') {
                    $placeholderMap[$placeholder] = $uploadedName;
                }
            } finally {
                @unlink($tempFile);
            }
        }

        return $placeholderMap;
    }

    /**
     * @param array<string, mixed> $workflow
     * @param array<string, string> $placeholderMap
     * @return array<string, mixed>
     */
    private function applyPlaceholderMap(array $workflow, array $placeholderMap): array
    {
        if (empty($placeholderMap)) {
            return $workflow;
        }

        $serialized = json_encode($workflow);
        if (!is_string($serialized)) {
            throw new \RuntimeException('Workflow payload could not be serialized.');
        }

        foreach ($placeholderMap as $placeholder => $uploadedName) {
            $serialized = str_replace($placeholder, $uploadedName, $serialized);
        }

        $decoded = json_decode($serialized, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Workflow payload could not be decoded after placeholder substitution.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function pollHistoryUntilComplete(string $endpoint, string $promptId): array
    {
        $maxAttempts = 30;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $historyResponse = Http::timeout(120)->get("{$endpoint}/history/{$promptId}");
            if (!$historyResponse->successful()) {
                throw new \RuntimeException('Failed to fetch prompt history from DevNode.');
            }

            $historyPayload = $historyResponse->json();
            if (!is_array($historyPayload)) {
                throw new \RuntimeException('Invalid history payload returned by DevNode.');
            }

            $historyEntry = $historyPayload[$promptId] ?? null;
            if (is_array($historyEntry)) {
                $status = strtolower(trim((string) ($historyEntry['status']['status_str'] ?? '')));
                if ($status === 'error') {
                    $message = trim((string) ($historyEntry['status']['message'] ?? 'DevNode run failed.'));
                    throw new \RuntimeException($message !== '' ? $message : 'DevNode run failed.');
                }

                $outputs = $historyEntry['outputs'] ?? null;
                if (is_array($outputs) && !empty($outputs)) {
                    return $historyEntry;
                }
            }

            usleep(200000);
        }

        throw new \RuntimeException('Timed out waiting for DevNode run outputs.');
    }

    /**
     * @param array<string, mixed> $outputs
     * @return array{filename: string, subfolder: string, type: string}
     */
    private function extractOutputFile(array $outputs, string $outputNodeId): array
    {
        $candidateNodeIds = [];
        if ($outputNodeId !== '') {
            $candidateNodeIds[] = $outputNodeId;
        }
        $candidateNodeIds = array_merge($candidateNodeIds, array_keys($outputs));

        foreach ($candidateNodeIds as $nodeId) {
            $nodeOutputs = $outputs[$nodeId] ?? null;
            if (!is_array($nodeOutputs)) {
                continue;
            }

            foreach (['videos', 'images', 'gifs'] as $bucket) {
                $items = $nodeOutputs[$bucket] ?? null;
                if (!is_array($items) || empty($items)) {
                    continue;
                }

                $first = $items[0] ?? null;
                if (!is_array($first)) {
                    continue;
                }

                $filename = trim((string) ($first['filename'] ?? ''));
                if ($filename === '') {
                    continue;
                }

                return [
                    'filename' => $filename,
                    'subfolder' => (string) ($first['subfolder'] ?? ''),
                    'type' => (string) ($first['type'] ?? 'output'),
                ];
            }
        }

        throw new \RuntimeException('DevNode history did not contain output files.');
    }
}

