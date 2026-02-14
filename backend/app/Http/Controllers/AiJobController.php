<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\File;
use App\Models\Video;
use App\Services\TokenLedgerService;
use App\Services\WorkflowPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

class AiJobController extends BaseController
{
    private const POSITIVE_PROMPT_PLACEHOLDER = '__POSITIVE_PROMPT__';
    private const NEGATIVE_PROMPT_PLACEHOLDER = '__NEGATIVE_PROMPT__';

    public function store(Request $request, TokenLedgerService $ledger): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_id' => 'numeric|required|exists:effects,id',
            'idempotency_key' => 'string|required|max:255',
            'provider' => 'string|nullable|max:50',
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
            $dispatch = null;
            if (in_array($existing->status, ['queued', 'processing'], true)) {
                $existingProvider = $existing->provider ?: (string) $request->input(
                    'provider',
                    config('services.comfyui.default_provider', 'local')
                );
                $dispatch = $this->ensureDispatch(
                    $existing,
                    (int) $request->input('priority', 0),
                    $existingProvider
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

        $tokenCost = (int) ceil((float) $effect->credits_cost);
        $provider = (string) $request->input('provider', config('services.comfyui.default_provider', 'local'));
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
            $inputPayload = $this->buildInputPayload($effect, $inputPayload, $inputFile);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        try {
            $job = DB::connection('tenant')->transaction(function () use ($request, $user, $tokenCost, $ledger, $idempotencyKey, $inputFileId, $resolvedVideoId, $provider, $inputPayload) {
                $aiJob = AiJob::query()->create([
                    'user_id' => $user->id,
                    'effect_id' => (int) $request->input('effect_id'),
                    'provider' => $provider,
                    'video_id' => $resolvedVideoId,
                    'input_file_id' => $inputFileId,
                    'status' => 'queued',
                    'idempotency_key' => $idempotencyKey,
                    'requested_tokens' => $tokenCost,
                    'reserved_tokens' => 0,
                    'consumed_tokens' => 0,
                    'input_payload' => $inputPayload,
                ]);

                $ledger->reserveForJob($aiJob, $tokenCost, [
                    'source' => 'ai_job_submission',
                    'effect_id' => (int) $request->input('effect_id'),
                ]);

                return $aiJob->fresh();
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'ai_jobs_tenant_idempotency_unique')) {
                $job = AiJob::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($job) {
                    return $this->sendResponse($job, 'Job already submitted');
                }
            }

            throw $e;
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Insufficient token balance.') {
                return $this->sendError('Insufficient tokens.', [
                    'required_tokens' => $tokenCost,
                ], 422);
            }

            throw $e;
        }

        $dispatch = $this->ensureDispatch($job, (int) $request->input('priority', 0), $provider);

        return $this->sendResponse($job, 'Job queued', [
            'dispatch_id' => $dispatch?->id,
        ]);
    }

    private function buildInputPayload(Effect $effect, array $inputPayload, ?File $inputFile): array
    {
        // New path: delegate to WorkflowPayloadService when effect has a workflow
        if ($effect->workflow_id && $effect->workflow) {
            $service = app(WorkflowPayloadService::class);
            $userInput = array_filter([
                'positive_prompt' => $this->normalizePrompt(data_get($inputPayload, 'positive_prompt')),
                'negative_prompt' => $this->normalizePrompt(data_get($inputPayload, 'negative_prompt')),
            ], fn ($v) => $v !== null);
            $resolvedProps = $service->resolveProperties($effect->workflow, $effect, $userInput);
            return $service->buildJobPayload($effect, $resolvedProps, $inputFile);
        }

        // Legacy path: effect without workflow_id (backward compat)
        if (!array_key_exists('workflow', $inputPayload)) {
            $inputPayload['workflow'] = $this->loadWorkflowFromEffect($effect);
        }

        if (!is_array($inputPayload['workflow'] ?? null) || empty($inputPayload['workflow'])) {
            throw new \RuntimeException('Workflow JSON is invalid or empty.');
        }

        $positivePrompt = $this->normalizePrompt(data_get($inputPayload, 'positive_prompt'));
        $negativePrompt = $this->normalizePrompt(data_get($inputPayload, 'negative_prompt'));
        $workflowSerialized = json_encode($inputPayload['workflow']);
        $hasPositivePlaceholder = is_string($workflowSerialized)
            && str_contains($workflowSerialized, self::POSITIVE_PROMPT_PLACEHOLDER);
        $hasNegativePlaceholder = is_string($workflowSerialized)
            && str_contains($workflowSerialized, self::NEGATIVE_PROMPT_PLACEHOLDER);

        if ($hasPositivePlaceholder || $hasNegativePlaceholder) {
            $inputPayload['workflow'] = $this->replacePromptPlaceholdersInValue(
                $inputPayload['workflow'],
                self::POSITIVE_PROMPT_PLACEHOLDER,
                self::NEGATIVE_PROMPT_PLACEHOLDER,
                $positivePrompt ?? '',
                $negativePrompt ?? ''
            );
        } elseif ($positivePrompt || $negativePrompt) {
            $inputPayload['workflow'] = $this->overrideWorkflowPrompts(
                $inputPayload['workflow'],
                $positivePrompt,
                $negativePrompt
            );
        }

        $inputPayload['input_path_placeholder'] = $inputPayload['input_path_placeholder']
            ?? ($effect->comfyui_input_path_placeholder ?: '__INPUT_PATH__');
        $inputPayload['output_extension'] = $inputPayload['output_extension']
            ?? ($effect->output_extension ?: 'mp4');
        $inputPayload['output_mime_type'] = $inputPayload['output_mime_type']
            ?? ($effect->output_mime_type ?: 'video/mp4');

        if (!array_key_exists('output_node_id', $inputPayload) && $effect->output_node_id) {
            $inputPayload['output_node_id'] = $effect->output_node_id;
        }

        if ($inputFile) {
            if (!array_key_exists('input_name', $inputPayload) && $inputFile->original_filename) {
                $inputPayload['input_name'] = $inputFile->original_filename;
            }
            if (!array_key_exists('input_mime_type', $inputPayload) && $inputFile->mime_type) {
                $inputPayload['input_mime_type'] = $inputFile->mime_type;
            }
        }

        return $inputPayload;
    }

    private function normalizePrompt(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function replacePromptPlaceholdersInValue(
        mixed $value,
        string $positivePlaceholder,
        string $negativePlaceholder,
        string $positivePrompt,
        string $negativePrompt
    ): mixed
    {
        if (is_string($value)) {
            if ($positivePlaceholder !== '') {
                $value = str_replace($positivePlaceholder, $positivePrompt, $value);
            }
            if ($negativePlaceholder !== '') {
                $value = str_replace($negativePlaceholder, $negativePrompt, $value);
            }
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $value[$key] = $this->replacePromptPlaceholdersInValue(
                    $nested,
                    $positivePlaceholder,
                    $negativePlaceholder,
                    $positivePrompt,
                    $negativePrompt
                );
            }
        }

        return $value;
    }

    private function overrideWorkflowPrompts(array $workflow, ?string $positivePrompt, ?string $negativePrompt): array
    {
        if (!$positivePrompt && !$negativePrompt) {
            return $workflow;
        }

        foreach ($workflow as $nodeId => $node) {
            if (!is_array($node)) {
                continue;
            }
            $inputs = $node['inputs'] ?? null;
            if (!is_array($inputs)) {
                continue;
            }

            $updated = false;
            if ($positivePrompt) {
                if (array_key_exists('prompt', $inputs)) {
                    $inputs['prompt'] = $positivePrompt;
                    $updated = true;
                }
                if (array_key_exists('text', $inputs)) {
                    $inputs['text'] = $positivePrompt;
                    $updated = true;
                }
            }
            if ($negativePrompt && array_key_exists('negative_prompt', $inputs)) {
                $inputs['negative_prompt'] = $negativePrompt;
                $updated = true;
            }

            if ($updated) {
                $node['inputs'] = $inputs;
                $workflow[$nodeId] = $node;
            }
        }

        return $workflow;
    }

    private function loadWorkflowFromEffect(Effect $effect): array
    {
        $path = (string) ($effect->comfyui_workflow_path ?? '');
        if ($path === '') {
            throw new \RuntimeException('Effect is not configured for processing.');
        }

        $disk = (string) config('services.comfyui.workflow_disk', 's3');

        try {
            if (!Storage::disk($disk)->exists($path)) {
                throw new \RuntimeException('Workflow file not found.');
            }
            $raw = Storage::disk($disk)->get($path);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Workflow file not found.');
        }

        $workflow = json_decode($raw ?: '', true);
        if (!is_array($workflow) || empty($workflow)) {
            throw new \RuntimeException('Workflow JSON is invalid or empty.');
        }
        if (!empty($workflow['_placeholder'])) {
            throw new \RuntimeException('Workflow file is a placeholder.');
        }

        return $workflow;
    }

    private function ensureDispatch(AiJob $job, int $priority = 0, ?string $provider = null): ?AiJobDispatch
    {
        $workflowId = null;
        $effect = Effect::query()->find($job->effect_id);
        if ($effect && $effect->workflow_id) {
            $workflowId = $effect->workflow_id;
        }

        try {
            return AiJobDispatch::query()->firstOrCreate([
                'tenant_id' => (string) $job->tenant_id,
                'tenant_job_id' => $job->id,
            ], [
                'provider' => $provider ?: ($job->provider ?: config('services.comfyui.default_provider', 'local')),
                'workflow_id' => $workflowId,
                'status' => 'queued',
                'priority' => $priority,
                'attempts' => 0,
            ]);
        } catch (\Throwable $e) {
            $job->status = 'failed';
            $job->error_message = 'Failed to enqueue job for dispatch.';
            $job->completed_at = now();
            $job->save();

            try {
                app(TokenLedgerService::class)->refundForJob($job, ['source' => 'dispatch_enqueue']);
            } catch (\Throwable $ledgerError) {
                // ignore refund failures to avoid masking dispatch errors
            }

            return null;
        }
    }
}
