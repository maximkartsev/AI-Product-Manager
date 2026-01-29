<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Tenant;
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

    public function handle(): void
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

            AiJobDispatch::query()->firstOrCreate([
                'tenant_id' => (string) $job->tenant_id,
                'tenant_job_id' => $job->id,
            ], [
                'provider' => $job->provider ?: config('services.comfyui.default_provider', 'local'),
                'status' => 'queued',
                'priority' => 0,
                'attempts' => 0,
            ]);
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $tenancy->end();
        }
    }
}
