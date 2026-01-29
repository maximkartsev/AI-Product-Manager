<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    protected static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$prepared) {
            try {
                DB::connection('central')->statement(
                    'CREATE DATABASE IF NOT EXISTS tenant_pool_1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
                DB::connection('central')->statement(
                    'CREATE DATABASE IF NOT EXISTS tenant_pool_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                );
            } catch (\Throwable $e) {
                // Ignore in environments that don't support CREATE DATABASE.
            }

            Artisan::call('migrate');
            Artisan::call('tenancy:pools-migrate');
            static::$prepared = true;
        }

        config(['queue.default' => 'sync']);
        $this->setPaymentWebhookSecret('');
    }

    public function test_successful_payment_creates_payment_and_credits_tokens(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 100,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.purchase_id', $purchase->id)
            ->assertJsonPath('data.credited_tokens', true);

        $payment = Payment::query()->where('transaction_id', $transactionId)->first();
        $this->assertNotNull($payment);
        $this->assertSame($purchase->id, $payment->purchase_id);
        $this->assertSame('succeeded', $payment->status);

        $purchase->refresh();
        $this->assertSame('completed', $purchase->status);
        $this->assertNotNull($purchase->processed_at);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame($user->id, (int) $wallet->user_id);
        $this->assertSame(100, (int) $wallet->balance);
        $this->assertSame(1, $this->getTokenTransactionCount($tenant->getKey(), $transactionId));
    }

    public function test_duplicate_successful_webhook_is_idempotent(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $payload = [
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 50,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 50,
        ];

        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame(50, (int) $wallet->balance);
        $this->assertSame(1, $this->getTokenTransactionCount($tenant->getKey(), $transactionId));
        $this->assertSame(1, Payment::query()->where('transaction_id', $transactionId)->count());
    }

    public function test_webhook_requires_purchase_and_transaction_id(): void
    {
        $response = $this->postWebhook([]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * @dataProvider invalidPurchaseIdProvider
     */
    public function test_webhook_rejects_invalid_purchase_id($invalidId): void
    {
        $response = $this->postWebhook([
            'purchase_id' => $invalidId,
            'transaction_id' => 'txn_' . uniqid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * @dataProvider invalidTransactionIdProvider
     */
    public function test_webhook_rejects_empty_transaction_id(string $transactionId): void
    {
        $response = $this->postWebhook([
            'purchase_id' => 1,
            'transaction_id' => $transactionId,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_webhook_returns_404_when_purchase_missing(): void
    {
        $response = $this->postWebhook([
            'purchase_id' => 999999,
            'transaction_id' => 'txn_missing_' . uniqid(),
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_webhook_rejects_invalid_signature_when_secret_set(): void
    {
        $this->setPaymentWebhookSecret('test-secret');

        $response = $this->postWebhook([
            'purchase_id' => 1,
            'transaction_id' => 'txn_' . uniqid(),
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_webhook_accepts_valid_signature(): void
    {
        $this->setPaymentWebhookSecret('test-secret');

        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $payload = [
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 10,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 10,
        ];

        $signature = $this->signatureForPayload($payload, 'test-secret');
        $response = $this->postWebhook($payload, ['X-Payment-Signature' => $signature]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.credited_tokens', true);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame(10, (int) $wallet->balance);
    }

    public function test_successful_payment_without_token_amount_does_not_credit_tokens(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', false);

        $this->assertNull($this->getTenantWallet($tenant->getKey()));
        $this->assertSame(0, $this->getTokenTransactionCount($tenant->getKey(), $transactionId));
    }

    public function test_tokens_field_fallback_credits_tokens(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'tokens' => 40,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', true);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame(40, (int) $wallet->balance);
    }

    /**
     * @dataProvider invalidTokenAmountProvider
     */
    public function test_non_positive_token_amount_does_not_credit(int $amount): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => $amount,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', false);

        $this->assertNull($this->getTenantWallet($tenant->getKey()));
        $this->assertSame(0, $this->getTokenTransactionCount($tenant->getKey(), $transactionId));
    }

    public function test_payment_status_updates_and_tokens_credit_on_late_success(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $pendingPayload = [
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 30,
        ];

        $this->postWebhook($pendingPayload)
            ->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', false);

        $purchase->refresh();
        $this->assertSame('pending', $purchase->status);
        $this->assertNull($purchase->processed_at);

        $successPayload = $pendingPayload;
        $successPayload['status'] = 'succeeded';

        $this->postWebhook($successPayload)
            ->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', true);

        $purchase->refresh();
        $this->assertSame('completed', $purchase->status);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame(30, (int) $wallet->balance);
        $this->assertSame(1, $this->getTokenTransactionCount($tenant->getKey(), $transactionId));
    }

    /**
     * @dataProvider successStatusProvider
     */
    public function test_success_statuses_credit_tokens(string $status): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => 20,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 5,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', true);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame(5, (int) $wallet->balance);
    }

    /**
     * @dataProvider nonSuccessStatusProvider
     */
    public function test_non_success_statuses_do_not_credit_tokens(string $status): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => 20,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 5,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', false);

        $purchase->refresh();
        $this->assertSame('pending', $purchase->status);
        $this->assertNull($this->getTenantWallet($tenant->getKey()));
    }

    public function test_payment_updates_on_second_webhook(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
        ])->assertStatus(200);

        $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 120,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 20,
        ])->assertStatus(200);

        $payment = Payment::query()->where('transaction_id', $transactionId)->first();
        $this->assertNotNull($payment);
        $this->assertSame(120.0, (float) $payment->amount);
        $this->assertSame('succeeded', $payment->status);
    }

    public function test_payment_processed_at_uses_payload_value(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();
        $processedAt = '2026-01-01 10:00:00';

        $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 10,
            'processed_at' => $processedAt,
        ])->assertStatus(200);

        $payment = Payment::query()->where('transaction_id', $transactionId)->first();
        $this->assertNotNull($payment);
        $this->assertSame($processedAt, $payment->processed_at?->format('Y-m-d H:i:s'));
    }

    public function test_purchase_processed_at_not_overwritten(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $original = Carbon::parse('2026-01-02 12:00:00');
        $purchase = $this->createPurchase($user, $tenant->getKey(), [
            'processed_at' => $original,
        ]);
        $transactionId = 'txn_' . uniqid();

        $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 10,
        ])->assertStatus(200);

        $purchase->refresh();
        $this->assertSame($original->format('Y-m-d H:i:s'), $purchase->processed_at?->format('Y-m-d H:i:s'));
    }

    public function test_metadata_array_is_saved_and_string_is_ignored(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();

        $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 5,
            'metadata' => ['source' => 'test'],
        ])->assertStatus(200);

        $payment = Payment::query()->where('transaction_id', $transactionId)->first();
        $this->assertNotNull($payment);
        $this->assertSame(['source' => 'test'], $payment->metadata);

        $transactionId2 = 'txn_' . uniqid();
        $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId2,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 5,
            'metadata' => 'not-array',
        ])->assertStatus(200);

        $payment2 = Payment::query()->where('transaction_id', $transactionId2)->first();
        $this->assertNotNull($payment2);
        $this->assertNull($payment2->metadata);
    }

    public function test_duplicate_provider_event_id_returns_duplicate_response(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, $tenant->getKey());
        $transactionId = 'txn_' . uniqid();
        $providerEventId = 'evt_' . uniqid();

        $payload = [
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 10,
            'provider_event_id' => $providerEventId,
        ];

        $this->postWebhook($payload)->assertStatus(200);
        $this->assertSame(1, PaymentEvent::query()->where('provider_event_id', $providerEventId)->count());

        $payload['amount'] = 200;
        $response = $this->postWebhook($payload);
        $response->assertStatus(200)
            ->assertJsonPath('data.duplicate_event', true)
            ->assertJsonPath('data.provider_event_id', $providerEventId);

        $payment = Payment::query()->where('transaction_id', $transactionId)->first();
        $this->assertNotNull($payment);
        $this->assertSame(100.0, (float) $payment->amount);
        $this->assertSame(1, PaymentEvent::query()->where('provider_event_id', $providerEventId)->count());
    }

    public function test_webhook_resolves_tenant_by_user_id_when_tenant_id_is_invalid(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, 'missing-tenant-' . uniqid());
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 70,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 70,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', true);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame($user->id, (int) $wallet->user_id);
        $this->assertSame(70, (int) $wallet->balance);
        $this->assertSame(1, $this->getTokenTransactionCount($tenant->getKey(), $transactionId));

        $missingWallet = $this->getTenantWallet($purchase->tenant_id);
        $this->assertNull($missingWallet);
    }

    public function test_webhook_resolves_tenant_when_tenant_id_is_empty(): void
    {
        [$user, $tenant] = $this->createUserAndTenant();
        $purchase = $this->createPurchase($user, '');
        $transactionId = 'txn_' . uniqid();

        $response = $this->postWebhook([
            'purchase_id' => $purchase->id,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount' => 70,
            'currency' => 'USD',
            'payment_gateway' => 'test',
            'token_amount' => 70,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.credited_tokens', true);

        $wallet = $this->getTenantWallet($tenant->getKey());
        $this->assertNotNull($wallet);
        $this->assertSame(70, (int) $wallet->balance);
    }

    private function createUserAndTenant(?string $tenantId = null): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'id' => $tenantId ?? (string) Str::uuid(),
            'user_id' => $user->id,
            'db_pool' => 'tenant_pool_1',
        ]);

        return [$user, $tenant];
    }

    private function createPurchase(User $user, string $tenantId, array $overrides = []): Purchase
    {
        return Purchase::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'package_id' => null,
            'original_amount' => 100.00,
            'applied_discount_amount' => 0.00,
            'total_amount' => 100.00,
            'status' => 'pending',
            'external_transaction_id' => null,
            'processed_at' => null,
        ], $overrides));
    }

    private function postWebhook(array $payload, array $headers = [])
    {
        return $this->postJson('/api/webhooks/payments', $payload, $headers);
    }

    private function getTenantWallet(string $tenantId)
    {
        return DB::connection('tenant_pool_1')
            ->table('token_wallets')
            ->where('tenant_id', $tenantId)
            ->first();
    }

    private function getTokenTransactionCount(string $tenantId, string $providerId): int
    {
        return (int) DB::connection('tenant_pool_1')
            ->table('token_transactions')
            ->where('tenant_id', $tenantId)
            ->where('provider_transaction_id', $providerId)
            ->count();
    }

    private function setPaymentWebhookSecret(string $secret): void
    {
        putenv("PAYMENT_WEBHOOK_SECRET={$secret}");
        $_ENV['PAYMENT_WEBHOOK_SECRET'] = $secret;
        $_SERVER['PAYMENT_WEBHOOK_SECRET'] = $secret;
    }

    private function signatureForPayload(array $payload, string $secret): string
    {
        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    public static function invalidPurchaseIdProvider(): array
    {
        return [
            'non-numeric' => ['abc'],
            'zero' => [0],
            'negative' => [-10],
        ];
    }

    public static function invalidTransactionIdProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    public static function invalidTokenAmountProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-10],
        ];
    }

    public static function successStatusProvider(): array
    {
        return [
            'success' => ['success'],
            'completed' => ['completed'],
            'paid' => ['paid'],
            'uppercase' => ['SUCCEEDED'],
        ];
    }

    public static function nonSuccessStatusProvider(): array
    {
        return [
            'failed' => ['failed'],
            'cancelled' => ['cancelled'],
            'refunded' => ['refunded'],
            'unknown' => ['unknown_status'],
        ];
    }
}
