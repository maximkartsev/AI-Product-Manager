<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Tenant;
use App\Services\TokenLedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Tenancy;

class ProcessAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public int $jobId
    ) {
    }

    public function handle(TokenLedgerService $ledger): void
    {
        $tenant = Tenant::query()->whereKey($this->tenantId)->first();
        if (!$tenant) {
            return;
        }

        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            $job = AiJob::query()->find($this->jobId);
            if (!$job) {
                return;
            }

            if (in_array($job->status, ['completed', 'failed'], true)) {
                return;
            }

            $job->status = 'processing';
            $job->started_at = $job->started_at ?: now();
            $job->save();

            // TODO: invoke AI provider and update job details.
            $job->status = 'completed';
            $job->completed_at = now();
            $job->save();

            $ledger->consumeForJob($job, ['source' => 'process_ai_job']);
        } catch (\Throwable $e) {
            $job = AiJob::query()->find($this->jobId);
            if ($job) {
                $job->status = 'failed';
                $job->error_message = $e->getMessage();
                $job->completed_at = now();
                $job->save();

                $ledger->refundForJob($job, ['source' => 'process_ai_job']);
            }
            throw $e;
        } finally {
            $tenancy->end();
        }
    }
}
