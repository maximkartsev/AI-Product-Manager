<?php

namespace Database\Seeders;

use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\Tenant;
use App\Models\TokenTransaction;
use App\Models\TokenWallet;
use App\Models\User;
use App\Services\TokenLedgerService;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Tenancy;

class AiJobsSeeder extends Seeder
{
    public function run(): void
    {
        $effect = Effect::query()->first();
        if (!$effect) {
            return;
        }

        $tenants = Tenant::query()->whereNotNull('user_id')->get();
        if ($tenants->isEmpty()) {
            return;
        }

        $tenancy = app(Tenancy::class);
        $ledger = app(TokenLedgerService::class);

        foreach ($tenants as $tenant) {
            $user = User::query()->find($tenant->user_id);
            if (!$user) {
                continue;
            }

            $tenancy->initialize($tenant);

            try {
                $this->seedTenantJobs($tenant, $user, $effect->id, $ledger);
            } finally {
                $tenancy->end();
            }
        }
    }

    private function seedTenantJobs(Tenant $tenant, User $user, int $effectId, TokenLedgerService $ledger): void
    {
        $wallet = TokenWallet::query()->firstOrCreate(
            ['tenant_id' => (string) $tenant->id],
            ['user_id' => $user->id, 'balance' => 0]
        );

        $this->ensureSeedBalance($wallet, $tenant);
        $wallet->refresh();

        // Resolve workflow_id from effect
        $effect = Effect::query()->find($effectId);
        $workflowId = $effect?->workflow_id;

        $completedJob = $this->createJob($tenant, $user, $effectId, 'seed-completed');
        $this->ensureDispatch($completedJob, $tenant, $workflowId, 'completed');
        $this->reserveAndConsume($completedJob, $wallet, $ledger);

        $wallet->refresh();
        $failedJob = $this->createJob($tenant, $user, $effectId, 'seed-failed');
        $this->ensureDispatch($failedJob, $tenant, $workflowId, 'failed');
        $this->reserveAndRefund($failedJob, $wallet, $ledger);
    }

    private function ensureDispatch(AiJob $job, Tenant $tenant, ?int $workflowId, string $status): void
    {
        AiJobDispatch::query()->firstOrCreate(
            [
                'tenant_id' => (string) $tenant->id,
                'tenant_job_id' => $job->id,
            ],
            [
                'provider' => $job->provider ?: config('services.comfyui.default_provider', 'local'),
                'workflow_id' => $workflowId,
                'status' => $status,
                'priority' => 0,
                'attempts' => 1,
            ]
        );
    }

    private function createJob(Tenant $tenant, User $user, int $effectId, string $suffix): AiJob
    {
        $idempotencyKey = "seed-{$tenant->id}-{$suffix}";

        return AiJob::query()->firstOrCreate(
            [
                'tenant_id' => (string) $tenant->id,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'user_id' => $user->id,
                'effect_id' => $effectId,
                'status' => 'queued',
                'requested_tokens' => 0,
                'reserved_tokens' => 0,
                'consumed_tokens' => 0,
                'provider_job_id' => "seed-job-{$tenant->id}-{$suffix}",
                'input_payload' => [
                    'prompt' => 'Seeded AI job payload',
                ],
            ]
        );
    }

    private function reserveAndConsume(AiJob $job, TokenWallet $wallet, TokenLedgerService $ledger): void
    {
        $tokenCost = $this->computeTokenCost($wallet);
        if ($tokenCost <= 0) {
            return;
        }

        $job->requested_tokens = $tokenCost;
        $job->save();

        $ledger->reserveForJob($job, $tokenCost, ['source' => 'seeder']);

        $job->status = 'completed';
        $job->completed_at = now();
        $job->save();

        $ledger->consumeForJob($job, ['source' => 'seeder']);
    }

    private function reserveAndRefund(AiJob $job, TokenWallet $wallet, TokenLedgerService $ledger): void
    {
        $tokenCost = $this->computeTokenCost($wallet);
        if ($tokenCost <= 0) {
            return;
        }

        $job->requested_tokens = $tokenCost;
        $job->save();

        $ledger->reserveForJob($job, $tokenCost, ['source' => 'seeder']);

        $job->status = 'failed';
        $job->error_message = 'Seeded failure for testing';
        $job->completed_at = now();
        $job->save();

        $ledger->refundForJob($job, ['source' => 'seeder']);
    }

    private function ensureSeedBalance(TokenWallet $wallet, Tenant $tenant): void
    {
        $minimumBalance = 50;
        if ((int) $wallet->balance >= $minimumBalance) {
            return;
        }

        $topupId = "seed-topup-{$tenant->id}";
        $existing = TokenTransaction::query()
            ->where('tenant_id', (string) $tenant->id)
            ->where('provider_transaction_id', $topupId)
            ->first();

        if ($existing) {
            return;
        }

        $topupAmount = $minimumBalance - (int) $wallet->balance;

        TokenTransaction::create([
            'tenant_id' => (string) $tenant->id,
            'user_id' => (int) $wallet->user_id,
            'amount' => $topupAmount,
            'type' => 'SEED_CREDIT',
            'provider_transaction_id' => $topupId,
            'description' => 'Seeder top-up',
            'metadata' => ['source' => 'seeder'],
        ]);

        $wallet->increment('balance', $topupAmount);
    }

    private function computeTokenCost(TokenWallet $wallet): int
    {
        $balance = (int) $wallet->balance;
        if ($balance <= 0) {
            return 0;
        }

        return min(20, $balance);
    }
}
