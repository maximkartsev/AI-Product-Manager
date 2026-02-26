<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\ComfyUiWorker;
use App\Models\ComfyUiWorkerSession;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\Effect;
use App\Models\File;
use App\Models\PartnerUsageEvent;
use App\Models\PartnerUsagePrice;
use App\Models\Tenant;
use App\Models\Video;
use App\Models\Workflow;
use App\Models\WorkerAuditLog;
use App\Services\OutputValidationService;
use App\Services\PresignedUrlService;
use App\Services\TokenLedgerService;
use App\Services\WorkerAuditService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    /**
     * Fleet self-registration: ASG workers register themselves to receive a token.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'worker_id' => 'required|string|max:255',
            'display_name' => 'string|nullable|max:255',
            'capabilities' => 'array|nullable',
            'max_concurrency' => 'integer|nullable|min:1',
            'fleet_slug' => 'required|string|max:128',
            'stage' => 'string|nullable|in:staging,production',
            'capacity_type' => 'string|nullable|in:spot,on-demand',
            'instance_type' => 'string|nullable|max:64',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $maxFleetWorkers = (int) config('services.comfyui.max_fleet_workers', 50);
        $currentFleetCount = ComfyUiWorker::query()
            ->where('registration_source', 'fleet')
            ->count();

        if ($currentFleetCount >= $maxFleetWorkers) {
            return $this->sendError('Fleet worker limit reached.', [], 403);
        }

        $workerId = $request->input('worker_id');

        // Prevent duplicate registration
        $existing = ComfyUiWorker::query()->where('worker_id', $workerId)->first();
        if ($existing) {
            return $this->sendError('Worker already registered.', [], 409);
        }

        // Validate instance belongs to a known ASG (if worker_id looks like an EC2 instance ID)
        if (str_starts_with($workerId, 'i-') && config('services.comfyui.validate_asg_instance', false)) {
            try {
                $asgClient = new \Aws\AutoScaling\AutoScalingClient([
                    'region' => config('services.comfyui.aws_region', config('services.ses.region', 'us-east-1')),
                    'version' => 'latest',
                ]);
                $result = $asgClient->describeAutoScalingInstances([
                    'InstanceIds' => [$workerId],
                ]);
                if (empty($result['AutoScalingInstances'])) {
                    return $this->sendError('Instance not found in any ASG.', [], 403);
                }
            } catch (\Throwable $e) {
                \Log::warning('ASG instance validation failed', [
                    'worker_id' => $workerId,
                    'error' => $e->getMessage(),
                ]);
                // Fail open — allow registration if AWS API is unreachable
            }
        }

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);

        $stage = (string) ($request->input('stage') ?: config('app.env'));
        if (!in_array($stage, ['staging', 'production'], true)) {
            return $this->sendError('Invalid stage. Expected staging or production.', [], 422);
        }

        $registrationStage = (string) config('services.comfyui.registration_stage', config('app.env'));
        if (in_array($registrationStage, ['staging', 'production'], true) && $stage !== $registrationStage) {
            return $this->sendError(
                "Stage mismatch. This backend accepts only {$registrationStage} workers.",
                [],
                422
            );
        }

        $fleetSlug = (string) $request->input('fleet_slug');
        $fleet = ComfyUiGpuFleet::query()
            ->where('stage', $stage)
            ->where('slug', $fleetSlug)
            ->first();
        if (!$fleet) {
            return $this->sendError('Fleet not found.', [], 404);
        }

        $workflowIds = ComfyUiWorkflowFleet::query()
            ->where('fleet_id', $fleet->id)
            ->where('stage', $stage)
            ->pluck('workflow_id')
            ->toArray();

        $workflowIds = Workflow::query()
            ->whereIn('id', $workflowIds)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        if (empty($workflowIds)) {
            return $this->sendError('No active workflows assigned to this fleet.', [], 422);
        }

        $worker = ComfyUiWorker::query()->create([
            'worker_id' => $workerId,
            'token_hash' => $tokenHash,
            'display_name' => $request->input('display_name', $workerId),
            'capabilities' => $request->input('capabilities'),
            'max_concurrency' => $request->input('max_concurrency', 1),
            'current_load' => 0,
            'last_seen_at' => now(),
            'is_draining' => false,
            'is_approved' => true,
            'last_ip' => $request->ip(),
            'registration_source' => 'fleet',
            'capacity_type' => $request->input('capacity_type'),
            'stage' => $stage,
        ]);

        $worker->workflows()->sync($workflowIds);

        try {
            ComfyUiWorkerSession::query()->create([
                'worker_id' => $worker->id,
                'worker_identifier' => $worker->worker_id,
                'fleet_slug' => $fleetSlug,
                'stage' => $stage,
                'instance_type' => $request->input('instance_type'),
                'lifecycle' => $worker->capacity_type,
                'started_at' => now(),
                'busy_seconds' => 0,
                'running_seconds' => 0,
            ]);
        } catch (\Throwable $e) {
            // non-blocking
        }

        try {
            app(WorkerAuditService::class)->log(
                'registered',
                $worker->id,
                $worker->worker_id,
                null,
                $request->ip(),
                [
                    'registration_source' => 'fleet',
                    'fleet_slug' => $fleetSlug,
                    'stage' => $stage,
                    'capacity_type' => $worker->capacity_type,
                ]
            );
        } catch (\Throwable $e) {
            // non-blocking
        }

        return $this->sendResponse([
            'worker_id' => $worker->worker_id,
            'token' => $plainToken,
            'workflows_assigned' => $worker->workflows()->pluck('slug')->toArray(),
            'fleet_slug' => $fleetSlug,
            'stage' => $stage,
        ], 'Worker registered');
    }

    /**
     * Fleet deregistration: worker removes itself on shutdown.
     */
    public function deregister(Request $request): JsonResponse
    {
        $worker = $request->attributes->get('authenticated_worker');
        $reason = $request->input('reason');

        try {
            app(WorkerAuditService::class)->log(
                'deregistered',
                $worker->id,
                $worker->worker_id,
                null,
                $request->ip(),
                $reason ? ['reason' => $reason] : null
            );
        } catch (\Throwable $e) {
            // non-blocking
        }

        $this->closeWorkerSession($worker?->worker_id);

        $worker->workflows()->detach();
        $worker->delete();

        return $this->sendResponse(null, 'Worker deregistered');
    }

    /**
     * Requeue a job due to infrastructure interruption (Spot, preemption).
     * Resets dispatch to 'queued' without counting as an attempt.
     */
    public function requeue(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dispatch_id' => 'required|integer',
            'lease_token' => 'required|string|max:64',
            'reason' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $dispatch = AiJobDispatch::query()->find($request->input('dispatch_id'));
        if (!$dispatch || $dispatch->lease_token !== $request->input('lease_token')) {
            return $this->sendError('Invalid dispatch or lease token.', [], 404);
        }

        $dispatch->update([
            'status' => 'queued',
            'worker_id' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error' => 'Requeued: ' . $request->input('reason'),
        ]);

        if ($dispatch->attempts > 0) {
            $dispatch->decrement('attempts');
        }

        $worker = $request->attributes->get('authenticated_worker');

        try {
            app(WorkerAuditService::class)->log(
                'requeued',
                $worker?->id,
                $worker?->worker_id,
                $dispatch->id,
                $request->ip(),
                ['reason' => $request->input('reason')]
            );
        } catch (\Throwable $e) {
            // non-blocking
        }

        return $this->sendResponse(null, 'Job requeued');
    }

    public function poll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'worker_id' => 'string|required|max:255',
            'display_name' => 'string|nullable|max:255',
            'capabilities' => 'array|nullable',
            'providers' => 'array|nullable',
            'providers.*' => 'string|max:50',
            'current_load' => 'integer|nullable|min:0',
            'max_concurrency' => 'integer|nullable|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $authenticatedWorker = $request->attributes->get('authenticated_worker');
        $worker = $this->updateWorkerFromRequest($authenticatedWorker, $request);

        if ($worker->is_draining) {
            return $this->sendResponse(['job' => null], 'Worker draining');
        }

        if ($worker->current_load >= $worker->max_concurrency) {
            return $this->sendResponse(['job' => null], 'Worker at capacity');
        }

        $leaseTtlSeconds = (int) config('services.comfyui.lease_ttl_seconds', self::DEFAULT_LEASE_TTL_SECONDS);
        $maxAttempts = (int) config('services.comfyui.max_attempts', self::DEFAULT_MAX_ATTEMPTS);

        $providers = (array) $request->input(
            'providers',
            [config('services.comfyui.default_provider', 'self_hosted')]
        );
        $providers = array_values(array_filter($providers));
        if (empty($providers)) {
            $providers = [config('services.comfyui.default_provider', 'self_hosted')];
        }
        // Get worker's assigned workflow IDs for dispatch filtering
        $workflowIds = $worker->workflows()->pluck('workflows.id')->toArray();

        $dispatch = $this->leaseDispatch(
            $worker->worker_id,
            $leaseTtlSeconds,
            $maxAttempts,
            $providers,
            $workflowIds,
            $worker->stage ?: 'production'
        );
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
            'output.metadata.partner_usage_events' => 'array|nullable',
            'output.metadata.partner_usage_events.*.node_id' => 'string|nullable|max:128',
            'output.metadata.partner_usage_events.*.node_class_type' => 'string|nullable|max:255',
            'output.metadata.partner_usage_events.*.node_display_name' => 'string|nullable|max:255',
            'output.metadata.partner_usage_events.*.provider' => 'string|nullable|max:100',
            'output.metadata.partner_usage_events.*.model' => 'string|nullable|max:255',
            'output.metadata.partner_usage_events.*.input_tokens' => 'numeric|nullable|min:0',
            'output.metadata.partner_usage_events.*.output_tokens' => 'numeric|nullable|min:0',
            'output.metadata.partner_usage_events.*.total_tokens' => 'numeric|nullable|min:0',
            'output.metadata.partner_usage_events.*.credits' => 'numeric|nullable|min:0',
            'output.metadata.partner_usage_events.*.cost_usd_reported' => 'numeric|nullable|min:0',
            'output.metadata.partner_usage_events.*.usage_json' => 'array|nullable',
            'output.metadata.partner_usage_events.*.ui_json' => 'array|nullable',
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
                $durationSeconds = $this->elapsedSeconds(
                    $this->asCarbon($job->started_at),
                    $this->asCarbon($job->completed_at)
                ) ?? 0;
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

            $partnerUsageEvents = data_get($request->input('output', []), 'metadata.partner_usage_events', []);
            if (is_array($partnerUsageEvents) && !empty($partnerUsageEvents)) {
                $this->persistPartnerUsageEvents(
                    $dispatch,
                    $job,
                    $request->input('worker_id'),
                    $request->input('provider_job_id'),
                    $partnerUsageEvents
                );
            }

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
            'capabilities' => $request->input('capabilities') ?? $worker->capabilities,
            'max_concurrency' => $request->input('max_concurrency', $worker->max_concurrency ?? 1),
            'current_load' => $request->input('current_load', $worker->current_load ?? 0),
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
        ]);
        $worker->save();
        return $worker;
    }

    private function leaseDispatch(
        string $workerId,
        int $leaseTtlSeconds,
        int $maxAttempts,
        array $providers,
        array $workflowIds = [],
        ?string $stage = null
    ): ?AiJobDispatch
    {
        return DB::connection('central')->transaction(function () use ($workerId, $leaseTtlSeconds, $maxAttempts, $providers, $workflowIds, $stage) {
            $now = now();
            $effectiveStage = $stage ?: 'production';

            $query = AiJobDispatch::query()
                ->where('stage', $effectiveStage)
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

            // Strict workflow filtering: workers only get jobs for their assigned workflows
            if (empty($workflowIds)) {
                // Worker has no workflow assignments — gets ZERO jobs
                return null;
            }
            $query->whereIn('workflow_id', $workflowIds);

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
            $isFirstLease = $dispatch->leased_at === null;
            $dispatch->leased_at = $dispatch->leased_at ?? $now;
            $dispatch->last_leased_at = $now;
            if ($isFirstLease) {
                $createdAt = $this->dispatchTimestamp($dispatch, 'created_at');
                if ($createdAt) {
                    $dispatch->queue_wait_seconds = $this->elapsedSeconds($createdAt, $now);
                }
            }
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
        $now = now();
        $dispatch->status = 'completed';
        $dispatch->worker_id = $workerId ?? $dispatch->worker_id;
        $dispatch->lease_expires_at = null;
        $dispatch->finished_at = $now;

        $lastLeaseAt = $this->dispatchTimestamp($dispatch, 'last_leased_at')
            ?? $this->dispatchTimestamp($dispatch, 'leased_at');
        if ($lastLeaseAt) {
            $dispatch->processing_seconds = $this->elapsedSeconds($lastLeaseAt, $now);
        }

        // Duration is measured from the earliest poll audit event for this dispatch.
        $dispatch->duration_seconds = null;
        $firstPollAtRaw = WorkerAuditLog::query()
            ->where('dispatch_id', $dispatch->id)
            ->where('event', 'poll')
            ->orderBy('created_at')
            ->value('created_at');
        $firstPollAt = $this->asCarbon($firstPollAtRaw);
        if ($firstPollAt) {
            $dispatch->duration_seconds = $this->elapsedSeconds($firstPollAt, $now);
        }

        if ($dispatch->queue_wait_seconds === null) {
            $createdAt = $this->dispatchTimestamp($dispatch, 'created_at');
            $leasedAt = $this->dispatchTimestamp($dispatch, 'leased_at');
            if ($createdAt && $leasedAt) {
                $dispatch->queue_wait_seconds = $this->elapsedSeconds($createdAt, $leasedAt);
            }
        }

        $dispatch->save();
        $this->incrementWorkerBusySeconds($dispatch->worker_id, $dispatch->processing_seconds);
    }

    private function markDispatchFailed(AiJobDispatch $dispatch, string $error, ?string $workerId = null): void
    {
        $now = now();
        $dispatch->status = 'failed';
        $dispatch->worker_id = $workerId ?? $dispatch->worker_id;
        $dispatch->last_error = $error;
        $dispatch->lease_expires_at = null;
        $dispatch->finished_at = $now;

        $lastLeaseAt = $this->dispatchTimestamp($dispatch, 'last_leased_at')
            ?? $this->dispatchTimestamp($dispatch, 'leased_at');
        if ($lastLeaseAt) {
            $dispatch->processing_seconds = $this->elapsedSeconds($lastLeaseAt, $now);
            $dispatch->duration_seconds = $dispatch->processing_seconds;
        }
        if ($dispatch->queue_wait_seconds === null) {
            $createdAt = $this->dispatchTimestamp($dispatch, 'created_at');
            $leasedAt = $this->dispatchTimestamp($dispatch, 'leased_at');
            if ($createdAt && $leasedAt) {
                $dispatch->queue_wait_seconds = $this->elapsedSeconds($createdAt, $leasedAt);
            }
        }
        $dispatch->save();
        $this->incrementWorkerBusySeconds($dispatch->worker_id, $dispatch->processing_seconds);
    }

    private function dispatchTimestamp(AiJobDispatch $dispatch, string $attribute): ?Carbon
    {
        $raw = $dispatch->getRawOriginal($attribute);
        if ($raw !== null && $raw !== '') {
            return $this->asCarbon($raw);
        }

        return $this->asCarbon($dispatch->{$attribute});
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function elapsedSeconds(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if (!$from || !$to) {
            return null;
        }

        return max(0, (int) floor($from->diffInSeconds($to, true)));
    }

    private function incrementWorkerBusySeconds(?string $workerIdentifier, ?int $seconds): void
    {
        if (!$workerIdentifier || !$seconds || $seconds <= 0) {
            return;
        }

        $session = ComfyUiWorkerSession::query()
            ->where('worker_identifier', $workerIdentifier)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
        if (!$session) {
            return;
        }

        $session->busy_seconds = (int) $session->busy_seconds + $seconds;
        $startedAt = $this->asCarbon($session->started_at);
        if ($startedAt) {
            $session->running_seconds = $this->elapsedSeconds($startedAt, now()) ?? 0;
            if ($session->running_seconds > 0) {
                $session->utilization = round($session->busy_seconds / $session->running_seconds, 4);
            }
        }
        $session->save();
    }

    private function closeWorkerSession(?string $workerIdentifier): void
    {
        if (!$workerIdentifier) {
            return;
        }

        $session = ComfyUiWorkerSession::query()
            ->where('worker_identifier', $workerIdentifier)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
        if (!$session) {
            return;
        }

        $endedAt = now();
        $session->ended_at = $endedAt;
        $startedAt = $this->asCarbon($session->started_at);
        if ($startedAt) {
            $session->running_seconds = $this->elapsedSeconds($startedAt, $endedAt) ?? 0;
            if ($session->running_seconds > 0) {
                $session->utilization = round($session->busy_seconds / $session->running_seconds, 4);
            }
        }
        $session->save();
    }

    private function persistPartnerUsageEvents(
        AiJobDispatch $dispatch,
        AiJob $job,
        ?string $workerIdentifier,
        ?string $comfyPromptId,
        array $events
    ): void {
        if (empty($events)) {
            return;
        }

        $now = now();
        $normalizedWorkerIdentifier = $this->normalizeUsageString($workerIdentifier, 255);
        $workerSessionId = $this->resolveWorkerSessionId($normalizedWorkerIdentifier);
        $fallbackPromptId = $this->normalizeUsageString($comfyPromptId, 255);

        $eventRows = [];
        $priceRowsByKey = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $nodeClassType = $this->normalizeUsageString($event['node_class_type'] ?? null, 255) ?: 'unknown';
            $provider = strtolower($this->normalizeUsageString($event['provider'] ?? null, 100) ?: 'unknown');
            $model = $this->normalizeUsageString($event['model'] ?? null, 255) ?? '';
            $nodeId = $this->normalizeUsageString($event['node_id'] ?? null, 128);
            $nodeDisplayName = $this->normalizeUsageString($event['node_display_name'] ?? null, 255);
            $promptId = $this->normalizeUsageString($event['comfy_prompt_id'] ?? null, 255) ?: $fallbackPromptId;

            $inputTokens = $this->normalizeUsageInt($event['input_tokens'] ?? null);
            $outputTokens = $this->normalizeUsageInt($event['output_tokens'] ?? null);
            $totalTokens = $this->normalizeUsageInt($event['total_tokens'] ?? null);
            $credits = $this->normalizeUsageFloat($event['credits'] ?? null, 6);
            $costUsdReported = $this->normalizeUsageFloat($event['cost_usd_reported'] ?? null, 8);
            $usageJson = is_array($event['usage_json'] ?? null) ? $event['usage_json'] : null;
            $uiJson = is_array($event['ui_json'] ?? null) ? $event['ui_json'] : null;

            if (
                $inputTokens === null
                && $outputTokens === null
                && $totalTokens === null
                && $credits === null
                && $costUsdReported === null
                && $usageJson === null
                && $uiJson === null
            ) {
                continue;
            }

            $eventRows[] = [
                'tenant_id' => $dispatch->tenant_id,
                'tenant_job_id' => $dispatch->tenant_job_id,
                'dispatch_id' => $dispatch->id,
                'workflow_id' => $dispatch->workflow_id,
                'effect_id' => $job->effect_id,
                'user_id' => $job->user_id,
                'worker_id' => $normalizedWorkerIdentifier,
                'worker_session_id' => $workerSessionId,
                'comfy_prompt_id' => $promptId,
                'node_id' => $nodeId,
                'node_class_type' => $nodeClassType,
                'node_display_name' => $nodeDisplayName,
                'provider' => $provider,
                'model' => $model !== '' ? $model : null,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'credits' => $credits,
                'cost_usd_reported' => $costUsdReported,
                'usage_json' => $usageJson,
                'ui_json' => $uiJson,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $priceRowKey = implode('|', [$provider, $nodeClassType, $model]);
            if (!isset($priceRowsByKey[$priceRowKey])) {
                $priceRowsByKey[$priceRowKey] = [
                    'provider' => $provider,
                    'node_class_type' => $nodeClassType,
                    'model' => $model,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'sample_ui_json' => $uiJson,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } else {
                $priceRowsByKey[$priceRowKey]['last_seen_at'] = $now;
                $priceRowsByKey[$priceRowKey]['updated_at'] = $now;
                if (
                    $priceRowsByKey[$priceRowKey]['sample_ui_json'] === null
                    && $uiJson !== null
                ) {
                    $priceRowsByKey[$priceRowKey]['sample_ui_json'] = $uiJson;
                }
            }
        }

        if (empty($eventRows)) {
            return;
        }

        PartnerUsageEvent::query()->insert($eventRows);
        if (!empty($priceRowsByKey)) {
            PartnerUsagePrice::query()->upsert(
                array_values($priceRowsByKey),
                ['provider', 'node_class_type', 'model'],
                ['last_seen_at', 'sample_ui_json', 'updated_at']
            );
        }
    }

    private function resolveWorkerSessionId(?string $workerIdentifier): ?int
    {
        if (!$workerIdentifier) {
            return null;
        }

        $openSession = ComfyUiWorkerSession::query()
            ->where('worker_identifier', $workerIdentifier)
            ->where(function ($query) {
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<=', now());
            })
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
        if ($openSession) {
            return (int) $openSession->id;
        }

        $latestSession = ComfyUiWorkerSession::query()
            ->where('worker_identifier', $workerIdentifier)
            ->where(function ($query) {
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<=', now());
            })
            ->orderByDesc('started_at')
            ->first();

        return $latestSession ? (int) $latestSession->id : null;
    }

    private function normalizeUsageString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }
        if (strlen($normalized) > $maxLength) {
            $normalized = substr($normalized, 0, $maxLength);
        }
        return $normalized;
    }

    private function normalizeUsageInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0) {
            return null;
        }
        return (int) round($number);
    }

    private function normalizeUsageFloat(mixed $value, int $precision): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0) {
            return null;
        }
        return round($number, $precision);
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
