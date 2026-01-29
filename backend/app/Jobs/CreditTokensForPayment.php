<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\Purchase;
use App\Services\TokenLedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreditTokensForPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $purchaseId,
        public int $paymentId,
        public int $tokenAmount,
        public ?array $metadata = null
    ) {
    }

    public function handle(TokenLedgerService $ledger): void
    {
        $purchase = Purchase::query()->find($this->purchaseId);
        $payment = Payment::query()->find($this->paymentId);

        if (!$purchase || !$payment) {
            return;
        }

        $ledger->creditFromPayment($purchase, $payment, $this->tokenAmount, $this->metadata);
    }
}
