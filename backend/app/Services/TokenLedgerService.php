<?php

namespace App\Services;

use App\Models\AiJob;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\TokenTransaction;
use App\Models\TokenWallet;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

class TokenLedgerService
{
    public function creditFromPayment(Purchase $purchase, Payment $payment, int $tokenAmount, ?array $metadata = null): bool
    {
        if ($tokenAmount <= 0) {
            return false;
        }

        $tenant = $this->resolveTenantForPurchase($purchase);
        if (!$tenant) {
            return false;
        }
        $tenantId = (string) $tenant->getKey();

        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            return DB::connection('tenant')->transaction(function () use ($purchase, $payment, $tokenAmount, $metadata, $tenantId) {
                $wallet = TokenWallet::query()->firstOrCreate(
                    ['tenant_id' => $tenantId],
                    ['user_id' => $purchase->user_id, 'balance' => 0]
                );

                if ((int) $wallet->user_id !== (int) $purchase->user_id) {
                    throw new \RuntimeException('Token wallet user mismatch for tenant.');
                }

                $existing = TokenTransaction::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider_transaction_id', $payment->transaction_id)
                    ->first();

                if ($existing) {
                    return false;
                }

                TokenTransaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $purchase->user_id,
                    'amount' => $tokenAmount,
                    'type' => 'PAYMENT_CREDIT',
                    'purchase_id' => $purchase->id,
                    'payment_id' => $payment->id,
                    'provider_transaction_id' => $payment->transaction_id,
                    'description' => 'Payment credit',
                    'metadata' => $metadata,
                ]);

                $wallet->increment('balance', $tokenAmount);

                return true;
            });
        } finally {
            $tenancy->end();
        }
    }

    public function reserveForJob(AiJob $job, int $tokenAmount, ?array $metadata = null): void
    {
        if ($tokenAmount <= 0) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($job, $tokenAmount, $metadata) {
            $wallet = TokenWallet::query()
                ->where('tenant_id', (string) $job->tenant_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = TokenWallet::query()->create([
                    'tenant_id' => (string) $job->tenant_id,
                    'user_id' => $job->user_id,
                    'balance' => 0,
                ]);
            }

            if ((int) $wallet->user_id !== (int) $job->user_id) {
                throw new \RuntimeException('Token wallet user mismatch for tenant.');
            }

            $existing = TokenTransaction::query()
                ->where('tenant_id', (string) $job->tenant_id)
                ->where('job_id', $job->id)
                ->where('type', 'JOB_RESERVE')
                ->first();

            if ($existing) {
                return;
            }

            if ((int) $wallet->balance < $tokenAmount) {
                throw new \RuntimeException('Insufficient token balance.');
            }

            TokenTransaction::create([
                'tenant_id' => (string) $job->tenant_id,
                'user_id' => $job->user_id,
                'amount' => -$tokenAmount,
                'type' => 'JOB_RESERVE',
                'job_id' => $job->id,
                'provider_transaction_id' => $this->jobProviderTransactionId($job->id, 'reserve'),
                'description' => 'Job token reservation',
                'metadata' => $metadata,
            ]);

            $wallet->decrement('balance', $tokenAmount);

            $job->reserved_tokens = max((int) $job->reserved_tokens, $tokenAmount);
            $job->save();
        });
    }

    public function consumeForJob(AiJob $job, ?array $metadata = null): void
    {
        DB::connection('tenant')->transaction(function () use ($job, $metadata) {
            $existing = TokenTransaction::query()
                ->where('tenant_id', (string) $job->tenant_id)
                ->where('job_id', $job->id)
                ->where('type', 'JOB_CONSUME')
                ->first();

            if ($existing) {
                return;
            }

            TokenTransaction::create([
                'tenant_id' => (string) $job->tenant_id,
                'user_id' => $job->user_id,
                'amount' => 0,
                'type' => 'JOB_CONSUME',
                'job_id' => $job->id,
                'provider_transaction_id' => $this->jobProviderTransactionId($job->id, 'consume'),
                'description' => 'Job token consumption',
                'metadata' => $metadata,
            ]);

            $job->consumed_tokens = max((int) $job->consumed_tokens, (int) $job->reserved_tokens);
            $job->save();
        });
    }

    public function refundForJob(AiJob $job, ?array $metadata = null): void
    {
        DB::connection('tenant')->transaction(function () use ($job, $metadata) {
            $existing = TokenTransaction::query()
                ->where('tenant_id', (string) $job->tenant_id)
                ->where('job_id', $job->id)
                ->where('type', 'JOB_REFUND')
                ->first();

            if ($existing) {
                return;
            }

            $refundAmount = (int) $job->reserved_tokens;
            if ($refundAmount <= 0) {
                return;
            }

            TokenTransaction::create([
                'tenant_id' => (string) $job->tenant_id,
                'user_id' => $job->user_id,
                'amount' => $refundAmount,
                'type' => 'JOB_REFUND',
                'job_id' => $job->id,
                'provider_transaction_id' => $this->jobProviderTransactionId($job->id, 'refund'),
                'description' => 'Job token refund',
                'metadata' => $metadata,
            ]);

            $wallet = TokenWallet::query()
                ->where('tenant_id', (string) $job->tenant_id)
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                $wallet->increment('balance', $refundAmount);
            }
        });
    }

    private function resolveTenantForPurchase(Purchase $purchase): ?Tenant
    {
        $tenantId = (string) $purchase->tenant_id;
        $tenant = $tenantId !== '' ? Tenant::query()->whereKey($tenantId)->first() : null;

        if (!$tenant) {
            $tenant = Tenant::query()->where('user_id', $purchase->user_id)->first();
        }

        return $tenant;
    }

    private function jobProviderTransactionId(int $jobId, string $suffix): string
    {
        return 'job:' . $jobId . ':' . $suffix;
    }
}
