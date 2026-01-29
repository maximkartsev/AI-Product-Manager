<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAiJob;
use App\Models\AiJob;
use App\Models\Effect;
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
            'input_payload' => 'array|nullable',
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
            return $this->sendResponse($existing, 'Job already submitted');
        }

        $effect = Effect::query()->find($request->input('effect_id'));
        if (!$effect) {
            return $this->sendError('Effect not found.', [], 404);
        }

        $tokenCost = (int) ceil((float) $effect->credits_cost);

        try {
            $job = DB::connection('tenant')->transaction(function () use ($request, $user, $tokenCost, $ledger, $idempotencyKey) {
                $aiJob = AiJob::query()->create([
                    'user_id' => $user->id,
                    'effect_id' => (int) $request->input('effect_id'),
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

        ProcessAiJob::dispatch((string) $job->tenant_id, $job->id);

        return $this->sendResponse($job, 'Job queued');
    }
}
