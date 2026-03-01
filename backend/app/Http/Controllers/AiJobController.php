<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\File;
use App\Models\User;
use App\Models\Video;
use App\Services\EffectRunSubmissionService;
use App\Services\WorkflowPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AiJobController extends BaseController
{

    public function store(Request $request, EffectRunSubmissionService $submissionService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_id' => 'numeric|required|exists:effects,id',
            'idempotency_key' => 'string|required|max:255',
            'video_id' => 'numeric|nullable',
            'input_file_id' => 'numeric|nullable',
            'input_payload' => 'array|nullable',
            'priority' => 'numeric|nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $activeJobCount = AiJob::where('user_id', $user->id)
            ->whereIn('status', ['queued', 'processing'])
            ->count();
        if ($activeJobCount >= 5) {
            return $this->sendError('Maximum concurrent processing jobs reached. Please wait for current jobs to complete.', [
                'active_jobs' => $activeJobCount,
                'max_allowed' => 5,
            ], 422);
        }

        $inputPayload = $request->input('input_payload');
        if (!is_array($inputPayload)) {
            $inputPayload = [];
        }

        $idempotencyKey = (string) $request->input('idempotency_key');
        $existing = AiJob::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $existingEffect = Effect::query()->find($existing->effect_id);
            if (!$existingEffect || !$existingEffect->is_active || $existingEffect->publication_status !== 'published') {
                return $this->sendError('Effect not found.', [], 404);
            }

            try {
                $runtime = $submissionService->buildRuntimeEffectForPublicRun($existingEffect);
            } catch (\RuntimeException $e) {
                return $this->sendError($e->getMessage(), [], 422);
            }

            $dispatch = null;
            if (in_array($existing->status, ['queued', 'processing'], true)) {
                $dispatch = $submissionService->ensureDispatchForJob(
                    $existing,
                    (int) $request->input('priority', 0),
                    null,
                    null,
                    (int) $runtime['workflow_id'],
                    (string) $runtime['dispatch_stage'],
                    null,
                    $runtime['selected_variant_id'] ?? null
                );
            }
            return $this->sendResponse($existing, 'Job already submitted', [
                'dispatch_id' => $dispatch?->id,
            ]);
        }

        $effect = Effect::query()->find($request->input('effect_id'));
        if (!$effect) {
            return $this->sendError('Effect not found.', [], 404);
        }
        if (!$effect->is_active || $effect->publication_status !== 'published') {
            return $this->sendError('Effect not found.', [], 404);
        }

        try {
            $runtime = $submissionService->buildRuntimeEffectForPublicRun($effect);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        $tokenCost = (int) ceil((float) $effect->credits_cost);
        $videoId = $request->input('video_id');
        $inputFileId = $request->input('input_file_id');

        if (!$videoId && !$inputFileId) {
            return $this->sendError('Input file is required.', [], 422);
        }

        $resolvedVideoId = null;
        $inputFile = null;
        if ($videoId) {
            $video = Video::query()->find((int) $videoId);
            if (!$video) {
                return $this->sendError('Video not found.', [], 404);
            }
            if ((int) $video->user_id !== (int) $user->id) {
                return $this->sendError('Video ownership mismatch.', [], 403);
            }
            if (!$inputFileId) {
                $inputFileId = $video->original_file_id;
            }
            if (!$inputFileId) {
                return $this->sendError('Input file is required.', [], 422);
            }
            $resolvedVideoId = $video->id;
        }

        if ($inputFileId) {
            $inputFile = File::query()->find((int) $inputFileId);
            if (!$inputFile) {
                return $this->sendError('File not found.', [], 404);
            }
            if ((int) $inputFile->user_id !== (int) $user->id) {
                return $this->sendError('File ownership mismatch.', [], 403);
            }
        }

        try {
            [$inputPayload, $workUnits] = $this->preparePayloadAndUnits($runtime['runtime_effect'], $inputPayload, $inputFile, $user);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        try {
            $submission = $submissionService->submitPrepared(
                user: $user,
                effect: $effect,
                idempotencyKey: $idempotencyKey,
                tokenCost: $tokenCost,
                videoId: $resolvedVideoId,
                inputFileId: $inputFileId ? (int) $inputFileId : null,
                preparedPayload: $inputPayload,
                workUnits: $workUnits['units'] ?? null,
                workUnitKind: $workUnits['kind'] ?? null,
                workflowId: (int) $runtime['workflow_id'],
                dispatchStage: (string) $runtime['dispatch_stage'],
                priority: (int) $request->input('priority', 0),
                benchmarkContextId: $runtime['selected_variant_id'] ?? null
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Insufficient token balance.') {
                return $this->sendError('Insufficient tokens.', [
                    'required_tokens' => $tokenCost,
                ], 422);
            }

            throw $e;
        }

        /** @var AiJob $job */
        $job = $submission['job'];
        /** @var AiJobDispatch|null $dispatch */
        $dispatch = $submission['dispatch'] ?? null;

        return $this->sendResponse($job, ($submission['already_submitted'] ?? false) ? 'Job already submitted' : 'Job queued', [
            'dispatch_id' => $dispatch?->id,
        ]);
    }

    /**
     * @return array{0: array, 1: array{units: float, kind: string}}
     */
    private function preparePayloadAndUnits(Effect $effect, array $inputPayload, ?File $inputFile, User $user): array
    {
        if (!$effect->workflow_id || !$effect->workflow) {
            throw new \RuntimeException('Effect is not configured for processing.');
        }

        $service = app(WorkflowPayloadService::class);
        $properties = $effect->workflow->properties ?? [];
        $allowed = $this->buildAllowedPropertyMap($properties);
        $userInput = $this->extractUserInput($inputPayload, $allowed, $user);
        $resolvedProps = $service->resolveProperties($effect->workflow, $effect, $userInput);
        $this->assertRequiredProperties($properties, $resolvedProps);
        $payload = $service->buildJobPayload($effect, $resolvedProps, $inputFile);
        $workUnits = $service->computeWorkUnitsFromResolvedProps($effect->workflow, $resolvedProps);

        return [$payload, $workUnits];
    }

    private function buildAllowedPropertyMap(array $properties): array
    {
        $allowed = [];
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            if (empty($prop['user_configurable']) || !empty($prop['is_primary_input'])) {
                continue;
            }
            $key = $prop['key'] ?? null;
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $allowed[$key] = $prop;
        }
        return $allowed;
    }

    private function extractUserInput(array $inputPayload, array $allowed, User $user): array
    {
        $userInput = [];
        foreach ($inputPayload as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                throw new \RuntimeException("Unsupported property: {$key}");
            }
            $prop = $allowed[$key];
            $type = $prop['type'] ?? 'text';
            if ($type === 'text') {
                $normalized = $this->normalizeTextInput($value);
                if ($normalized !== null) {
                    $userInput[$key] = $normalized;
                }
                continue;
            }
            if (in_array($type, ['image', 'video'], true)) {
                $fileId = $this->normalizeFileId($value);
                if (!$fileId) {
                    throw new \RuntimeException("Invalid file id for {$key}.");
                }
                $file = File::query()->find($fileId);
                if (!$file) {
                    throw new \RuntimeException("File not found for {$key}.");
                }
                if ((int) $file->user_id !== (int) $user->id) {
                    throw new \RuntimeException("File ownership mismatch for {$key}.");
                }
                $expiresAt = data_get($file->metadata, 'expires_at');
                if ($expiresAt && now()->gte(\Carbon\Carbon::parse($expiresAt))) {
                    throw new \RuntimeException("File has expired for {$key}.");
                }
                if ($expiresAt) {
                    $metadata = $file->metadata ?? [];
                    unset($metadata['expires_at']);
                    $file->metadata = $metadata;
                    $file->save();
                }
                if (!$this->matchesFileType($file->mime_type, $type)) {
                    throw new \RuntimeException("File type mismatch for {$key}.");
                }
                $userInput[$key] = [
                    'disk' => $file->disk,
                    'path' => $file->path,
                ];
                continue;
            }

            $normalized = $this->normalizeTextInput($value);
            if ($normalized !== null) {
                $userInput[$key] = $normalized;
            }
        }

        return $userInput;
    }

    private function assertRequiredProperties(array $properties, array $resolvedProps): void
    {
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            if (empty($prop['required']) || !empty($prop['is_primary_input'])) {
                continue;
            }
            $key = $prop['key'] ?? null;
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $type = $prop['type'] ?? 'text';
            $value = $resolvedProps[$key] ?? null;
            if ($type === 'text') {
                $text = is_string($value) ? trim($value) : '';
                if ($text === '') {
                    throw new \RuntimeException("Missing required property: {$key}");
                }
                continue;
            }
            if (in_array($type, ['image', 'video'], true)) {
                if (is_array($value)) {
                    $path = $value['path'] ?? null;
                    if (is_string($path) && trim($path) !== '') {
                        continue;
                    }
                } elseif (is_string($value) && trim($value) !== '') {
                    continue;
                }
                throw new \RuntimeException("Missing required property: {$key}");
            }
            if ($value === null || $value === '') {
                throw new \RuntimeException("Missing required property: {$key}");
            }
        }
    }

    private function normalizeTextInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeFileId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }
        if (is_numeric($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }
        return null;
    }

    private function matchesFileType(?string $mimeType, string $expectedType): bool
    {
        if (!$mimeType) {
            return false;
        }
        $normalized = strtolower($mimeType);
        return $expectedType === 'image'
            ? str_starts_with($normalized, 'image/')
            : str_starts_with($normalized, 'video/');
    }

}
