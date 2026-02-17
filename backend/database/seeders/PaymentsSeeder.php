<?php

namespace Database\Seeders;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TokenLedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class PaymentsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var TokenLedgerService $ledger */
        $ledger = app(TokenLedgerService::class);

        $users = User::query()->get();
        if ($users->isEmpty()) {
            return;
        }

        foreach ($users as $user) {
            $tenant = Tenant::query()->where('user_id', $user->id)->first();
            if (!$tenant) {
                continue;
            }

            $this->seedPaidTransactions($user, $tenant, $ledger);
        }
    }

    private function seedPaidTransactions(User $user, Tenant $tenant, TokenLedgerService $ledger): void
    {
        $now = now();
        $purchaseColumns = Schema::getColumnListing('purchases');
        $seedPayments = [
            [
                'external_id' => "seed-purchase-{$user->id}-1",
                'transaction_id' => "seed-txn-{$user->id}-1",
                'event_id' => "seed-event-{$user->id}-1",
                'amount' => 49.99,
                'token_amount' => 100,
            ],
            [
                'external_id' => "seed-purchase-{$user->id}-2",
                'transaction_id' => "seed-txn-{$user->id}-2",
                'event_id' => "seed-event-{$user->id}-2",
                'amount' => 99.00,
                'token_amount' => 250,
            ],
        ];

        foreach ($seedPayments as $seed) {
            $purchase = null;
            if (in_array('external_transaction_id', $purchaseColumns, true)) {
                $purchase = Purchase::query()
                    ->where('external_transaction_id', $seed['external_id'])
                    ->first();
            }

            if (!$purchase) {
                $purchase = new Purchase();
            }

            $this->setIfColumnExists($purchase, $purchaseColumns, 'external_transaction_id', $seed['external_id']);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'tenant_id', $tenant->id);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'user_id', $user->id);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'package_id', null);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'original_amount', $seed['amount']);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'applied_discount_amount', 0);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'total_amount', $seed['amount']);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'total', $seed['amount']);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'subtotal', $seed['amount']);
            $this->setIfColumnExists($purchase, $purchaseColumns, 'status', 'completed');
            $this->setIfColumnExists($purchase, $purchaseColumns, 'processed_at', $now);
            $purchase->save();

            if ($purchase->status !== 'completed') {
                $purchase->status = 'completed';
                $purchase->processed_at = $purchase->processed_at ?: $now;
                $purchase->save();
            }

            $payment = Payment::query()->firstOrCreate(
                ['transaction_id' => $seed['transaction_id']],
                [
                    'purchase_id' => $purchase->id,
                    'status' => 'succeeded',
                    'amount' => $seed['amount'],
                    'currency' => 'USD',
                    'payment_gateway' => 'seed',
                    'processed_at' => $now,
                    'metadata' => ['source' => 'seeder'],
                ]
            );

            if ($payment->purchase_id !== $purchase->id) {
                $payment->purchase_id = $purchase->id;
                $payment->save();
            }

            PaymentEvent::query()->firstOrCreate(
                ['provider_event_id' => $seed['event_id']],
                [
                    'provider' => 'seed',
                    'purchase_id' => $purchase->id,
                    'payment_id' => $payment->id,
                    'payload' => [
                        'purchase_id' => $purchase->id,
                        'transaction_id' => $seed['transaction_id'],
                        'status' => 'succeeded',
                        'amount' => $seed['amount'],
                    ],
                    'received_at' => $now,
                    'processed_at' => $now,
                ]
            );

            $ledger->creditFromPayment($purchase, $payment, $seed['token_amount'], [
                'source' => 'seeder',
                'provider_event_id' => $seed['event_id'],
            ]);
        }
    }

    private function setIfColumnExists(Purchase $purchase, array $columns, string $column, mixed $value): void
    {
        if (in_array($column, $columns, true)) {
            $purchase->setAttribute($column, $value);
        }
    }
}
