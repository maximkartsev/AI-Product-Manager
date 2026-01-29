<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\File;
use App\Models\Video;
use App\Services\TokenLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

class AiJobController extends BaseController
{
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
            $job = DB::connection('tenant')->transaction(function () use ($request, $user, $tokenCost, $ledger, $idempotencyKey, $inputFileId, $resolvedVideoId, $provider) {
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
                    'input_payload' => $request->input('input_payload'),
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

    private function ensureDispatch(AiJob $job, int $priority = 0, ?string $provider = null): ?AiJobDispatch
    {
        try {
            return AiJobDispatch::query()->firstOrCreate([
                'tenant_id' => (string) $job->tenant_id,
                'tenant_job_id' => $job->id,
            ], [
                'provider' => $provider ?: ($job->provider ?: config('services.comfyui.default_provider', 'local')),
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
