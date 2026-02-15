<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\ComfyUiWorker;
use App\Models\Effect;
use App\Models\File;
use App\Models\Tenant;
use App\Models\Video;
use App\Services\OutputValidationService;
use App\Services\PresignedUrlService;
use App\Services\TokenLedgerService;
use App\Services\WorkerAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;

class ComfyUiWorkerController extends BaseController
{
    private const DEFAULT_LEASE_TTL_SECONDS = 900;
    private const DEFAULT_MAX_ATTEMPTS = 3;

    public function poll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'worker_id' => 'string|required|max:255',
            'display_name' => 'string|nullable|max:255',
            'environment' => 'string|nullable|max:50',
            'capabilities' => 'array|nullable',
            'providers' => 'array|nullable',
            'providers.*' => 'string|max:50',
            'current_load' => 'integer|nullable|min:0',
            'max_concurrency' => 'integer|nullable|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        // Use authenticated worker from middleware if available (per-worker token auth)
        $authenticatedWorker = $request->attributes->get('authenticated_worker');
        $worker = $authenticatedWorker
            ? $this->updateWorkerFromRequest($authenticatedWorker, $request)
            : $this->upsertWorker($request);

        if ($worker->is_draining) {
            return $this->sendResponse(['job' => null], 'Worker draining');
        }

        if (!$worker->is_approved && $authenticatedWorker) {
            return $this->sendResponse(['job' => null], 'Worker not approved');
        }

        if ($worker->current_load >= $worker->max_concurrency) {
            return $this->sendResponse(['job' => null], 'Worker at capacity');
        }

        $leaseTtlSeconds = (int) config('services.comfyui.lease_ttl_seconds', self::DEFAULT_LEASE_TTL_SECONDS);
        $maxAttempts = (int) config('services.comfyui.max_attempts', self::DEFAULT_MAX_ATTEMPTS);

        $providers = (array) $request->input(
            'providers',
            [config('services.comfyui.default_provider', 'local')]
        );
        $providers = array_values(array_filter($providers));
        if (empty($providers)) {
            $providers = [config('services.comfyui.default_provider', 'local')];
        }

        // Get worker's assigned workflow IDs for dispatch filtering
        $workflowIds = $worker->workflows()->pluck('workflows.id')->toArray();

        $dispatch = $this->leaseDispatch($worker->worker_id, $leaseTtlSeconds, $maxAttempts, $providers, $workflowIds);
        if (!$dispatch) {
            return $this->sendResponse(['job' => null], 'No jobs available');
        }

        $payload = $this->buildJobPayload($dispatch);
        if (!$payload) {
            return $this->sendResponse(['job' => null], 'No job payload available');
        }

        // Audit log on job leased
        try {
            app(WorkerAuditService::class)->log(
                'poll',
                $worker->id,
                $worker->worker_id,
                $dispatch->id,
                $request->ip()
            );
        } catch (\Throwable $e) {
            // non-blocking
        }

        return $this->sendResponse(['job' => $payload], 'Job leased');
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dispatch_id' => 'integer|required',
            'lease_token' => 'string|required|max:64',
            'worker_id' => 'string|nullable|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $dispatch = AiJobDispatch::query()->find($request->input('dispatch_id'));
        if (!$dispatch || $dispatch->lease_token !== $request->input('lease_token')) {
            return $this->sendError('Lease not found.', [], 404);
        }

        $leaseTtlSeconds = (int) config('services.comfyui.lease_ttl_seconds', self::DEFAULT_LEASE_TTL_SECONDS);
        $base = $dispatch->lease_expires_at && $dispatch->lease_expires_at->gt(now())
            ? $dispatch->lease_expires_at
            : now();
        $dispatch->lease_expires_at = $base->copy()->addSeconds($leaseTtlSeconds);
        $dispatch->save();

        if ($request->filled('worker_id')) {
            ComfyUiWorker::query()
                ->where('worker_id', $request->input('worker_id'))
                ->update(['last_seen_at' => now()]);
        }

        return $this->sendResponse([
            'lease_expires_at' => $dispatch->lease_expires_at?->toIso8601String(),
        ], 'Lease extended');
    }

    public function complete(Request $request, TokenLedgerService $ledger, OutputValidationService $outputValidator, WorkerAuditService $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dispatch_id' => 'integer|required',
            'lease_token' => 'string|required|max:64',
            'worker_id' => 'string|nullable|max:255',
            'provider_job_id' => 'string|nullable|max:255',
            'output' => 'array|nullable',
            'output.size' => 'integer|nullable|min:0',
            'output.mime_type' => 'string|nullable|max:255',
            'output.metadata' => 'array|nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $dispatch = AiJobDispatch::query()->find($request->input('dispatch_id'));
        if (!$dispatch || $dispatch->lease_token !== $request->input('lease_token')) {
            return $this->sendError('Lease not found.', [], 404);
        }

        $result = $this->withTenant($dispatch->tenant_id, function () use ($dispatch, $request, $ledger) {
            $job = AiJob::query()->find($dispatch->tenant_job_id);
            if (!$job) {
                $this->markDispatchFailed($dispatch, 'Job not found in tenant DB.');
                return null;
            }

            if ($job->status === 'completed') {
                $this->markDispatchCompleted($dispatch, $request->input('worker_id'));
                return $job;
            }

            if ($job->status === 'failed') {
                $this->markDispatchFailed($dispatch, $job->error_message ?: 'Job already failed.');
                return $job;
            }

            $job->status = 'completed';
            $job->completed_at = now();
            $job->error_message = null;
            if ($request->filled('provider_job_id')) {
                $job->provider_job_id = (string) $request->input('provider_job_id');
            }

            if ($job->output_file_id) {
                $this->updateOutputFile($job->output_file_id, $request->input('output', []));
            }

            $job->save();

            if ($job->started_at && $job->completed_at) {
                $durationSeconds = (int) $job->started_at->diffInSeconds($job->completed_at);
                if ($durationSeconds > 0) {
                    Effect::query()
                        ->whereKey($job->effect_id)
                        ->update(['last_processing_time_seconds' => $durationSeconds]);
                }
            }

            if ($job->video_id) {
                Video::query()->whereKey($job->video_id)->update([
                    'status' => 'completed',
                    'processed_file_id' => $job->output_file_id,
                    'processing_details' => $request->input('output'),
                ]);
            }

            $ledger->consumeForJob($job, ['source' => 'worker_complete']);
            $this->markDispatchCompleted($dispatch, $request->input('worker_id'));

            return $job;
        });

        if (!$result) {
            return $this->sendError('Failed to complete job.', [], 500);
        }

        // Output validation (non-blocking)
        try {
            if ($result->output_file_id) {
                $outputFile = File::query()->find($result->output_file_id);
                if ($outputFile && $outputFile->disk && $outputFile->path) {
                    $validation = $outputValidator->validate($outputFile->disk, $outputFile->path);
                    if (!($validation['valid'] ?? false)) {
                        \Log::warning('Output validation failed', [
                            'dispatch_id' => $dispatch->id,
                            'error' => $validation['error'] ?? 'unknown',
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-blocking
        }

        // Audit log
        try {
            $worker = $request->attributes->get('authenticated_worker');
            $audit->log(
                'complete',
                $worker?->id,
                $request->input('worker_id'),
                $dispatch->id,
                $request->ip()
            );
        } catch (\Throwable $e) {
            // non-blocking
        }

        return $this->sendResponse(['job_id' => $result->id], 'Job completed');
    }

    public function fail(Request $request, TokenLedgerService $ledger, WorkerAuditService $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dispatch_id' => 'integer|required',
            'lease_token' => 'string|required|max:64',
            'worker_id' => 'string|nullable|max:255',
            'error_message' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $dispatch = AiJobDispatch::query()->find($request->input('dispatch_id'));
        if (!$dispatch || $dispatch->lease_token !== $request->input('lease_token')) {
            return $this->sendError('Lease not found.', [], 404);
        }

        $errorMessage = $this->sanitizeWorkerError((string) $request->input('error_message', 'Processing failed. Try another video'));

        $this->withTenant($dispatch->tenant_id, function () use ($dispatch, $ledger, $errorMessage, $request) {
            $job = AiJob::query()->find($dispatch->tenant_job_id);
            if (!$job) {
                $this->markDispatchFailed($dispatch, 'Job not found in tenant DB.');
                return null;
            }

            if ($job->status === 'completed') {
                $this->markDispatchCompleted($dispatch, $request->input('worker_id'));
                return null;
            }

            if ($job->status === 'failed') {
                $this->markDispatchFailed($dispatch, $job->error_message ?: $errorMessage, $request->input('worker_id'));
                return null;
            }

            $job->status = 'failed';
            $job->error_message = $errorMessage;
            $job->completed_at = now();
            $job->save();

            if ($job->video_id) {
                Video::query()->whereKey($job->video_id)->update([
                    'status' => 'failed',
                    'processing_details' => ['error' => $errorMessage],
                ]);
            }

            $ledger->refundForJob($job, ['source' => 'worker_fail']);
            $this->markDispatchFailed($dispatch, $errorMessage, $request->input('worker_id'));
            return null;
        });

        // Audit log
        try {
            $worker = $request->attributes->get('authenticated_worker');
            $audit->log(
                'fail',
                $worker?->id,
                $request->input('worker_id'),
                $dispatch->id,
                $request->ip(),
                ['error' => $errorMessage]
            );
        } catch (\Throwable $e) {
            // non-blocking
        }

        return $this->sendResponse(['dispatch_id' => $dispatch->id], 'Job failed');
    }

    private function sanitizeWorkerError(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return 'Processing failed.';
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $message = $decoded['exception_message'] ?? $decoded['error_message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        if (preg_match('/exception_message\\"?:\\"([^\\"]+)/', $trimmed, $match)) {
            $candidate = trim($match[1]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $firstLine = strtok($trimmed, "\n");
        return $firstLine !== false && trim($firstLine) !== '' ? trim($firstLine) : 'Processing failed.';
    }

    private function updateWorkerFromRequest(ComfyUiWorker $worker, Request $request): ComfyUiWorker
    {
        $worker->fill([
            'display_name' => $request->input('display_name') ?? $worker->display_name,
            'environment' => $request->input('environment', $worker->environment ?? 'cloud'),
            'capabilities' => $request->input('capabilities') ?? $worker->capabilities,
            'max_concurrency' => $request->input('max_concurrency', $worker->max_concurrency ?? 1),
            'current_load' => $request->input('current_load', $worker->current_load ?? 0),
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
        ]);
        $worker->save();
        return $worker;
    }

    private function upsertWorker(Request $request): ComfyUiWorker
    {
        $workerId = (string) $request->input('worker_id');

        $defaults = [
            'display_name' => $request->input('display_name'),
            'environment' => $request->input('environment', 'cloud'),
            'capabilities' => $request->input('capabilities'),
            'max_concurrency' => $request->input('max_concurrency', 1),
            'current_load' => $request->input('current_load', 0),
            'last_seen_at' => now(),
        ];

        $worker = ComfyUiWorker::query()->where('worker_id', $workerId)->first();
        if ($worker) {
            $worker->fill($defaults);
            $worker->save();
            return $worker;
        }

        return ComfyUiWorker::query()->create(array_merge(['worker_id' => $workerId], $defaults));
    }

    private function leaseDispatch(string $workerId, int $leaseTtlSeconds, int $maxAttempts, array $providers, array $workflowIds = []): ?AiJobDispatch
    {
        return DB::connection('central')->transaction(function () use ($workerId, $leaseTtlSeconds, $maxAttempts, $providers, $workflowIds) {
            $now = now();

            $query = AiJobDispatch::query()
                ->whereIn('provider', $providers)
                ->where('attempts', '<', $maxAttempts)
                ->where(function ($q) use ($now) {
                    $q
                        ->where('status', 'queued')
                        ->orWhere(function ($sub) use ($now) {
                            $sub->where('status', 'leased')
                                ->whereNotNull('lease_expires_at')
                                ->where('lease_expires_at', '<=', $now);
                        });
                });

            // If worker has assigned workflows, filter dispatches to those workflows
            if (!empty($workflowIds)) {
                $query->where(function ($q) use ($workflowIds) {
                    $q->whereIn('workflow_id', $workflowIds)
                      ->orWhereNull('workflow_id');
                });
            }

            $dispatch = $query
                ->orderByDesc('priority')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (!$dispatch) {
                return null;
            }

            $dispatch->status = 'leased';
            $dispatch->worker_id = $workerId;
            $dispatch->lease_token = Str::uuid()->toString();
            $dispatch->lease_expires_at = $now->copy()->addSeconds($leaseTtlSeconds);
            $dispatch->attempts = (int) $dispatch->attempts + 1;
            $dispatch->save();

            return $dispatch;
        });
    }

    private function buildJobPayload(AiJobDispatch $dispatch): ?array
    {
        return $this->withTenant($dispatch->tenant_id, function () use ($dispatch) {
            $job = AiJob::query()->find($dispatch->tenant_job_id);
            if (!$job) {
                $this->markDispatchFailed($dispatch, 'Job not found in tenant DB.');
                return null;
            }

            if (in_array($job->status, ['completed', 'failed'], true)) {
                $this->markDispatchCompleted($dispatch, null);
                return null;
            }

            if ($job->status !== 'processing') {
                $job->status = 'processing';
                $job->started_at = $job->started_at ?: now();
                $job->save();
            }

            if ($job->video_id) {
                Video::query()->whereKey($job->video_id)->update([
                    'status' => 'processing',
                ]);
            }

            $inputFile = $this->resolveInputFile($job);
            $outputFile = $this->ensureOutputFile($job);

            $presigned = app(PresignedUrlService::class);
            $ttlSeconds = (int) config(
                'services.comfyui.presigned_ttl_seconds',
                self::DEFAULT_LEASE_TTL_SECONDS
            );

            $inputUrl = null;
            if ($inputFile) {
                $inputUrl = $presigned->downloadUrl($inputFile->disk, $inputFile->path, $ttlSeconds);
            }

            $outputUpload = null;
            if ($outputFile) {
                $contentType = data_get($job->input_payload, 'output_mime_type', 'video/mp4');
                $outputUpload = $presigned->uploadUrl(
                    $outputFile->disk,
                    $outputFile->path,
                    $ttlSeconds,
                    $contentType
                );
            }

            // Presign asset download URLs in input_payload
            $inputPayload = $job->input_payload ?? [];
            if (!empty($inputPayload['assets']) && is_array($inputPayload['assets'])) {
                foreach ($inputPayload['assets'] as &$asset) {
                    if (!empty($asset['s3_path']) && !empty($asset['s3_disk'])) {
                        try {
                            $asset['download_url'] = $presigned->downloadUrl(
                                $asset['s3_disk'],
                                $asset['s3_path'],
                                $ttlSeconds
                            );
                        } catch (\Throwable $e) {
                            $asset['download_url'] = null;
                        }
                    }
                }
                unset($asset);
                $inputPayload['assets'] = array_values($inputPayload['assets']);
            }

            return [
                'dispatch_id' => $dispatch->id,
                'lease_token' => $dispatch->lease_token,
                'lease_expires_at' => $dispatch->lease_expires_at?->toIso8601String(),
                'provider' => $dispatch->provider,
                'tenant_id' => $dispatch->tenant_id,
                'job_id' => $job->id,
                'effect_id' => $job->effect_id,
                'input_payload' => $inputPayload,
                'input_file' => $inputFile ? [
                    'id' => $inputFile->id,
                    'path' => $inputFile->path,
                    'disk' => $inputFile->disk,
                ] : null,
                'output_file' => $outputFile ? [
                    'id' => $outputFile->id,
                    'path' => $outputFile->path,
                    'disk' => $outputFile->disk,
                ] : null,
                'input_url' => $inputUrl,
                'output_url' => $outputUpload['url'] ?? null,
                'output_headers' => $outputUpload['headers'] ?? [],
            ];
        });
    }

    private function resolveInputFile(AiJob $job): ?File
    {
        if ($job->input_file_id) {
            return File::query()->find($job->input_file_id);
        }

        $payloadInputFileId = data_get($job->input_payload, 'input_file_id');
        if ($payloadInputFileId) {
            $file = File::query()->find($payloadInputFileId);
            if ($file) {
                $job->input_file_id = $file->id;
                $job->save();
            }
            return $file;
        }

        return null;
    }

    private function ensureOutputFile(AiJob $job): ?File
    {
        if ($job->output_file_id) {
            return File::query()->find($job->output_file_id);
        }

        $disk = (string) config('filesystems.default', 's3');
        $extension = data_get($job->input_payload, 'output_extension', 'mp4');
        $path = sprintf(
            'tenants/%s/ai-jobs/%d/output-%s.%s',
            (string) $job->tenant_id,
            $job->id,
            Str::uuid()->toString(),
            $extension
        );

        $file = File::query()->create([
            'tenant_id' => (string) $job->tenant_id,
            'user_id' => $job->user_id,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'video/mp4',
        ]);

        $job->output_file_id = $file->id;
        $job->save();

        return $file;
    }

    private function updateOutputFile(int $fileId, array $output): void
    {
        $file = File::query()->find($fileId);
        if (!$file) {
            return;
        }

        $updates = [];
        if (isset($output['size'])) {
            $updates['size'] = (int) $output['size'];
        }
        if (isset($output['mime_type'])) {
            $updates['mime_type'] = (string) $output['mime_type'];
        }
        if (isset($output['metadata'])) {
            $updates['metadata'] = $output['metadata'];
        }

        if ($file->disk && $file->path) {
            $updates['url'] = Storage::disk($file->disk)->url($file->path);
        }

        if (!empty($updates)) {
            $file->update($updates);
        }
    }

    private function markDispatchCompleted(AiJobDispatch $dispatch, ?string $workerId): void
    {
        $dispatch->status = 'completed';
        $dispatch->worker_id = $workerId ?? $dispatch->worker_id;
        $dispatch->lease_expires_at = null;

        // Compute duration from audit log poll event
        $pollTime = \App\Models\WorkerAuditLog::query()
            ->where('dispatch_id', $dispatch->id)
            ->where('event', 'poll')
            ->min('created_at');
        if ($pollTime) {
            $dispatch->duration_seconds = (int) abs(now()->diffInSeconds(\Carbon\Carbon::parse($pollTime)));
        }

        $dispatch->save();
    }

    private function markDispatchFailed(AiJobDispatch $dispatch, string $error, ?string $workerId = null): void
    {
        $dispatch->status = 'failed';
        $dispatch->worker_id = $workerId ?? $dispatch->worker_id;
        $dispatch->last_error = $error;
        $dispatch->lease_expires_at = null;
        $dispatch->save();
    }

    private function withTenant(string $tenantId, \Closure $callback)
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();
        if (!$tenant) {
            return null;
        }

        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            return $callback();
        } finally {
            $tenancy->end();
        }
    }
}
