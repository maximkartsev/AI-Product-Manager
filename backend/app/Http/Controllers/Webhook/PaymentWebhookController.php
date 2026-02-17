<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\BaseController;
use App\Jobs\CreditTokensForPayment;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        if (!$this->verifySignature($request)) {
            return $this->sendError('Invalid webhook signature.', [], 401);
        }

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
        $providerEventId = trim((string) $request->input('provider_event_id', $request->input('event_id', '')));
        $paymentEvent = null;
        if ($providerEventId !== '') {
            $paymentEvent = PaymentEvent::query()->firstOrCreate(
                ['provider_event_id' => $providerEventId],
                [
                    'provider' => $paymentGateway,
                    'payload' => $request->all(),
                    'received_at' => now(),
                ]
            );

            if (!$paymentEvent->wasRecentlyCreated && $paymentEvent->processed_at) {
                return $this->sendResponse([
                    'received' => true,
                    'duplicate_event' => true,
                    'provider_event_id' => $providerEventId,
                ], 'Webhook already processed');
            }
        }

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
            $tokenAmount = (int) $request->input('token_amount', $request->input('tokens', 0));
            if ($tokenAmount > 0) {
                CreditTokensForPayment::dispatch(
                    $purchase->id,
                    $payment->id,
                    $tokenAmount,
                    is_array($metadata) ? $metadata : null
                );
                $creditedTokens = true;
            }
        }

        if ($paymentEvent) {
            $paymentEvent->purchase_id = $purchase->id;
            $paymentEvent->payment_id = $payment->id;
            $paymentEvent->payload = $request->all();
            $paymentEvent->processed_at = now();
            $paymentEvent->save();
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

    private function verifySignature(Request $request): bool
    {
        $secret = (string) env('PAYMENT_WEBHOOK_SECRET', '');
        if ($secret === '') {
            return true;
        }

        $signature = (string) $request->header('X-Payment-Signature', '');
        if ($signature === '') {
            return false;
        }

        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}

