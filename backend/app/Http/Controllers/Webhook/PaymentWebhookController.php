<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\BaseController;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\TokenTransaction;
use App\Models\TokenWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

class PaymentWebhookController extends BaseController
{
    /**
     * Webhook entrypoint (central domain).
     *
     * Writes payment entities in the central DB, then credits tokens in the tenant DB
     * on payment success (idempotent).
     */
    public function handle(Request $request): JsonResponse
    {
        // TODO: verify signatures + parse provider payloads (Stripe/Paddle/etc).
        $purchaseId = (int) $request->input('purchase_id');
        $transactionId = trim((string) $request->input('transaction_id', ''));

        if ($purchaseId <= 0 || $transactionId === '') {
            return $this->sendError(
                'Missing required payment identifiers.',
                ['purchase_id' => 'required', 'transaction_id' => 'required'],
                422
            );
        }

        /** @var Purchase|null $purchase */
        $purchase = Purchase::query()->find($purchaseId);
        if (!$purchase) {
            return $this->sendError('Purchase not found.', [], 404);
        }

        $status = (string) $request->input('status', 'pending');
        $amount = (float) $request->input('amount', 0);
        $currency = (string) $request->input('currency', 'USD');
        $paymentGateway = (string) $request->input('payment_gateway', 'unknown');
        $processedAt = $request->input('processed_at');
        $metadata = $request->input('metadata');

        $paymentData = [
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'payment_gateway' => $paymentGateway,
            'processed_at' => $processedAt ?: (self::isPaymentSuccessful($status) ? now() : null),
            'metadata' => is_array($metadata) ? $metadata : null,
        ];

        /** @var Payment|null $payment */
        $payment = Payment::query()->where('transaction_id', $transactionId)->first();
        if ($payment) {
            $payment->fill($paymentData);
            $payment->save();
        } else {
            $payment = Payment::create($paymentData);
        }

        $creditedTokens = false;
        if (self::isPaymentSuccessful($status)) {
            if ($purchase->status !== 'completed') {
                $purchase->status = 'completed';
                $purchase->processed_at = $purchase->processed_at ?: now();
                $purchase->save();
            }

            $creditedTokens = $this->creditTokens($purchase, $payment, $request);
        }

        return $this->sendResponse([
            'received' => true,
            'purchase_id' => $purchase->id,
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'credited_tokens' => $creditedTokens,
        ], 'Webhook received');
    }

    private static function isPaymentSuccessful(string $status): bool
    {
        return in_array(strtolower($status), ['succeeded', 'success', 'completed', 'paid'], true);
    }

    private function creditTokens(Purchase $purchase, Payment $payment, Request $request): bool
    {
        $tokenAmount = (int) $request->input('token_amount', $request->input('tokens', 0));
        if ($tokenAmount <= 0) {
            return false;
        }

        $tenantId = (string) $purchase->tenant_id;
        /** @var Tenant|null $tenant */
        $tenant = $tenantId !== '' ? Tenant::query()->whereKey($tenantId)->first() : null;
        if (!$tenant) {
            $tenant = Tenant::query()->where('user_id', $purchase->user_id)->first();
            if (!$tenant) {
                return false;
            }
            $tenantId = (string) $tenant->getKey();
        }

        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            DB::connection('tenant')->transaction(function () use ($purchase, $payment, $tenantId, $tokenAmount, $request) {
                /** @var TokenWallet $wallet */
                $wallet = TokenWallet::query()->firstOrCreate(
                    ['tenant_id' => $tenantId],
                    ['user_id' => $purchase->user_id, 'balance' => 0],
                );

                if ((int) $wallet->user_id !== (int) $purchase->user_id) {
                    throw new \RuntimeException('Token wallet user mismatch for tenant.');
                }

                $existing = TokenTransaction::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider_transaction_id', $payment->transaction_id)
                    ->first();

                if ($existing) {
                    return;
                }

                $metadata = $request->input('metadata');

                TokenTransaction::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $purchase->user_id,
                    'amount' => $tokenAmount,
                    'type' => 'PAYMENT_CREDIT',
                    'purchase_id' => $purchase->id,
                    'payment_id' => $payment->id,
                    'provider_transaction_id' => $payment->transaction_id,
                    'description' => 'Payment credit',
                    'metadata' => is_array($metadata) ? $metadata : null,
                ]);

                $wallet->increment('balance', $tokenAmount);
            });
        } finally {
            $tenancy->end();
        }

        return true;
    }
}

