<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TokenTransaction;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Tenancy;

class TokenWalletSeeder extends Seeder
{
    public function run(): void
    {
        $minimumBalance = (int) (env('SEED_TOKEN_BALANCE', 100));
        if ($minimumBalance <= 0) {
            return;
        }

        $tenants = Tenant::query()->whereNotNull('user_id')->get();
        if ($tenants->isEmpty()) {
            return;
        }

        $tenancy = app(Tenancy::class);

        foreach ($tenants as $tenant) {
            $user = User::query()->find($tenant->user_id);
            if (!$user) {
                continue;
            }

            $tenancy->initialize($tenant);

            try {
                if (!Schema::connection('tenant')->hasTable('token_wallets')) {
                    continue;
                }
                if (!Schema::connection('tenant')->hasTable('token_transactions')) {
                    continue;
                }

                $wallet = TokenWallet::query()->firstOrCreate(
                    ['tenant_id' => (string) $tenant->id],
                    ['user_id' => $user->id, 'balance' => 0]
                );

                if ((int) $wallet->user_id !== (int) $user->id) {
                    continue;
                }

                if ((int) $wallet->balance >= $minimumBalance) {
                    continue;
                }

                $topupId = "seed-wallet-{$tenant->id}";
                $existing = TokenTransaction::query()
                    ->where('tenant_id', (string) $tenant->id)
                    ->where('provider_transaction_id', $topupId)
                    ->first();

                if ($existing) {
                    continue;
                }

                $topupAmount = $minimumBalance - (int) $wallet->balance;

                TokenTransaction::create([
                    'tenant_id' => (string) $tenant->id,
                    'user_id' => (int) $wallet->user_id,
                    'amount' => $topupAmount,
                    'type' => 'SEED_CREDIT',
                    'provider_transaction_id' => $topupId,
                    'description' => 'Seeder wallet top-up',
                    'metadata' => ['source' => 'seeder'],
                ]);

                $wallet->increment('balance', $topupAmount);
            } finally {
                $tenancy->end();
            }
        }
    }
}
