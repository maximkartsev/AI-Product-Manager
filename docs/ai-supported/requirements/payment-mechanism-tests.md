# Payment Mechanism Test Plan

## Overview

This document outlines comprehensive test cases for the payment webhook mechanism, covering both regular flows and edge cases. The payment system involves:

- **Central DB**: `purchases`, `payments` tables
- **Tenant DB**: `token_wallets`, `token_transactions` tables
- **Webhook Handler**: `PaymentWebhookController`
- **Key Features**: Idempotent token crediting, cross-DB operations, tenant resolution

---

## Test Categories

### 1. Webhook Input Validation

#### 1.1 Regular Cases
- ✅ **Valid webhook payload**
  - All required fields present (`purchase_id`, `transaction_id`)
  - Valid data types and formats
  - Expected: 200 response, payment created/updated

- ✅ **Webhook with optional fields**
  - Includes `metadata`, `processed_at`, `token_amount`
  - Expected: All fields stored correctly

#### 1.2 Edge Cases
- ❌ **Missing `purchase_id`**
  - Expected: 422 error, "Missing required payment identifiers"

- ❌ **Missing `transaction_id`**
  - Expected: 422 error, "Missing required payment identifiers"

- ❌ **Invalid `purchase_id` (non-numeric)**
  - Payload: `purchase_id: "abc"`
  - Expected: 422 error or 404 if cast to 0

- ❌ **Invalid `purchase_id` (zero or negative)**
  - Payload: `purchase_id: 0` or `purchase_id: -1`
  - Expected: 422 error

- ❌ **Empty `transaction_id`**
  - Payload: `transaction_id: ""`
  - Expected: 422 error

- ❌ **Whitespace-only `transaction_id`**
  - Payload: `transaction_id: "   "`
  - Expected: 422 error (after trim)

---

### 2. Purchase Resolution

#### 2.1 Regular Cases
- ✅ **Purchase exists in central DB**
  - Valid `purchase_id` pointing to existing purchase
  - Expected: Purchase retrieved successfully

#### 2.2 Edge Cases
- ❌ **Purchase not found**
  - Payload: `purchase_id: 99999` (non-existent)
  - Expected: 404 error, "Purchase not found"

- ❌ **Purchase with null `tenant_id`**
  - Purchase exists but `tenant_id` is NULL
  - Expected: Tenant resolution falls back to `user_id` lookup

- ❌ **Purchase with empty string `tenant_id`**
  - Purchase has `tenant_id: ""`
  - Expected: Tenant resolution falls back to `user_id` lookup

---

### 3. Payment Creation and Updates

#### 3.1 Regular Cases
- ✅ **New payment creation**
  - First webhook for a `transaction_id`
  - Expected: New `Payment` record created in central DB

- ✅ **Payment update (status change)**
  - Webhook with existing `transaction_id` but different status
  - Expected: Existing `Payment` record updated

- ✅ **Payment update (amount change)**
  - Webhook with existing `transaction_id` but different amount
  - Expected: Existing `Payment` record updated

- ✅ **Payment with all fields**
  - Includes: `status`, `amount`, `currency`, `payment_gateway`, `processed_at`, `metadata`
  - Expected: All fields stored correctly

#### 3.2 Edge Cases
- ⚠️ **Duplicate webhook (same data)**
  - Same `transaction_id` with identical data
  - Expected: Payment updated (no error), idempotent behavior

- ⚠️ **Payment status transition (pending → succeeded)**
  - First webhook: `status: "pending"`
  - Second webhook: `status: "succeeded"`
  - Expected: Payment updated, tokens credited on second webhook

- ⚠️ **Payment status transition (succeeded → failed)**
  - First webhook: `status: "succeeded"` (tokens credited)
  - Second webhook: `status: "failed"`
  - Expected: Payment updated, but tokens NOT debited (current implementation doesn't handle reversals)

---

### 4. Payment Status Handling

#### 4.1 Successful Payment Statuses
- ✅ **Status: "succeeded"**
  - Expected: `isPaymentSuccessful()` returns true, tokens credited

- ✅ **Status: "success"**
  - Expected: `isPaymentSuccessful()` returns true, tokens credited

- ✅ **Status: "completed"**
  - Expected: `isPaymentSuccessful()` returns true, tokens credited

- ✅ **Status: "paid"**
  - Expected: `isPaymentSuccessful()` returns true, tokens credited

- ✅ **Case-insensitive status**
  - Payload: `status: "SUCCEEDED"` or `status: "Paid"`
  - Expected: Treated as successful (lowercase comparison)

#### 4.2 Non-Successful Payment Statuses
- ⚠️ **Status: "pending"**
  - Expected: Payment recorded, tokens NOT credited, purchase status unchanged

- ⚠️ **Status: "failed"**
  - Expected: Payment recorded, tokens NOT credited, purchase status unchanged

- ⚠️ **Status: "cancelled"**
  - Expected: Payment recorded, tokens NOT credited, purchase status unchanged

- ⚠️ **Status: "refunded"**
  - Expected: Payment recorded, tokens NOT credited (no reversal logic)

- ⚠️ **Unknown status**
  - Payload: `status: "unknown_status"`
  - Expected: Payment recorded, tokens NOT credited

---

### 5. Purchase Status Updates

#### 5.1 Regular Cases
- ✅ **Purchase marked as completed on successful payment**
  - Purchase initially `status: "pending"`
  - Successful payment webhook received
  - Expected: Purchase `status` → `"completed"`, `processed_at` set

- ✅ **Purchase already completed**
  - Purchase already `status: "completed"`
  - Another successful payment webhook received
  - Expected: Purchase remains `"completed"`, no duplicate processing

#### 5.2 Edge Cases
- ⚠️ **Purchase `processed_at` already set**
  - Purchase has `processed_at: "2026-01-01 10:00:00"`
  - Successful payment webhook received
  - Expected: `processed_at` NOT overwritten (uses `?: now()`)

---

### 6. Tenant Resolution

#### 6.1 Regular Cases
- ✅ **Tenant resolved by `purchase.tenant_id`**
  - Purchase has valid `tenant_id`
  - Expected: Tenant found directly, tenancy initialized

- ✅ **Tenant resolved by `purchase.user_id` fallback**
  - Purchase has empty/null `tenant_id` but valid `user_id`
  - Tenant exists with matching `user_id`
  - Expected: Tenant found via fallback, tenancy initialized

#### 6.2 Edge Cases
- ❌ **Tenant not found (by `tenant_id` or `user_id`)**
  - Purchase has invalid `tenant_id` and `user_id` doesn't match any tenant
  - Expected: `creditTokens()` returns `false`, no tokens credited, webhook still recorded

- ❌ **Tenant `tenant_id` mismatch**
  - Purchase `tenant_id` exists but points to different tenant
  - Expected: Should still resolve correctly (uses `tenant_id` directly)

- ⚠️ **Multiple tenants with same `user_id` (shouldn't happen in 1:1 model)**
  - Edge case if data integrity is violated
  - Expected: First tenant found used (or error handling)

---

### 7. Token Crediting

#### 7.1 Regular Cases
- ✅ **Token credit on successful payment**
  - Webhook includes `token_amount: 100`
  - Expected: `TokenTransaction` created, `TokenWallet.balance` incremented by 100

- ✅ **Token credit with `token_amount` field**
  - Payload: `token_amount: 50`
  - Expected: 50 tokens credited

- ✅ **Token credit with `tokens` field (fallback)**
  - Payload: `tokens: 50` (no `token_amount`)
  - Expected: 50 tokens credited

- ✅ **Token wallet auto-creation**
  - No `TokenWallet` exists for tenant
  - Expected: Wallet created with `balance: 0`, then tokens credited

- ✅ **Token wallet exists**
  - `TokenWallet` already exists with `balance: 50`
  - Expected: Balance incremented to 150 (if 100 tokens credited)

- ✅ **Token transaction with all fields**
  - Includes: `purchase_id`, `payment_id`, `provider_transaction_id`, `description`, `metadata`
  - Expected: All fields stored correctly

#### 7.2 Edge Cases
- ⚠️ **Missing token amount**
  - Webhook has no `token_amount` or `tokens` field
  - Expected: `creditTokens()` returns `false`, no tokens credited, payment still recorded

- ⚠️ **Zero token amount**
  - Payload: `token_amount: 0`
  - Expected: `creditTokens()` returns `false`, no tokens credited

- ⚠️ **Negative token amount**
  - Payload: `token_amount: -10`
  - Expected: `creditTokens()` returns `false` (or should validate and reject)

- ⚠️ **Token wallet user mismatch**
  - `TokenWallet` exists with different `user_id` than `purchase.user_id`
  - Expected: `RuntimeException` thrown, transaction rolled back, no tokens credited

- ⚠️ **Very large token amount**
  - Payload: `token_amount: 999999999`
  - Expected: Should handle integer overflow (check `balance` column type)

---

### 8. Idempotency

#### 8.1 Regular Cases
- ✅ **Duplicate webhook (same `transaction_id`)**
  - First webhook: Creates payment, credits tokens
  - Second webhook: Same `transaction_id` and `status: "succeeded"`
  - Expected: Payment updated, tokens NOT credited again (idempotency check)

- ✅ **Duplicate webhook (same `provider_event_id`)**
  - First webhook: Creates payment + payment_event
  - Second webhook: Same `provider_event_id`
  - Expected: Controller returns `duplicate_event` and skips updates

- ✅ **Idempotency key: `provider_transaction_id`**
  - `TokenTransaction` already exists with same `tenant_id` + `provider_transaction_id`
  - Expected: No new transaction created, wallet balance unchanged

#### 8.2 Edge Cases
- ⚠️ **Webhook retry with different status**
  - First webhook: `status: "pending"` (no tokens credited)
  - Second webhook: `status: "succeeded"` (same `transaction_id`)
  - Expected: Payment updated, tokens credited (new status triggers credit)

- ⚠️ **Webhook retry after partial failure**
  - First webhook: Fails during token credit (DB error)
  - Second webhook: Retry with same data
  - Expected: Payment updated, tokens credited successfully

- ⚠️ **Concurrent webhooks (race condition)**
  - Two webhooks with same `transaction_id` arrive simultaneously
  - Expected: Only one token credit succeeds (DB unique constraint or transaction lock)

---

### 9. Cross-DB Transaction Integrity

#### 9.1 Regular Cases
- ✅ **Successful end-to-end flow**
  - Central DB: Purchase + Payment created/updated
  - Tenant DB: TokenTransaction created, TokenWallet updated
  - Expected: All operations succeed atomically

#### 9.2 Edge Cases
- ❌ **Central DB failure (Purchase save)**
  - Purchase update fails (constraint violation, DB error)
  - Expected: Error returned, no payment update, no tokens credited

- ❌ **Tenant DB failure (TokenWallet update)**
  - TokenWallet increment fails (DB error, constraint violation)
  - Expected: Tenant transaction rolled back, no tokens credited, payment still recorded in central DB

- ❌ **Tenant DB connection failure**
  - Tenant DB unavailable during token credit
  - Expected: Exception caught, tenancy ended, error returned

- ⚠️ **Partial success scenario**
  - Payment created in central DB
  - Token credit fails in tenant DB
  - Expected: Payment recorded, tokens NOT credited (manual reconciliation needed)

---

### 10. Tenancy Lifecycle

#### 10.1 Regular Cases
- ✅ **Tenancy initialized correctly**
  - Tenant resolved, tenancy initialized
  - Expected: `DB::connection('tenant')` routes to correct pool DB

- ✅ **Tenancy ended after success**
  - Token credit succeeds
  - Expected: `$tenancy->end()` called in `finally` block

- ✅ **Tenancy ended after failure**
  - Token credit fails
  - Expected: `$tenancy->end()` called in `finally` block (cleanup)

#### 10.2 Edge Cases
- ⚠️ **Tenancy already initialized**
  - Webhook called within existing tenancy context
  - Expected: Should handle gracefully (may override or error)

- ⚠️ **Tenancy initialization failure**
  - `Tenancy::initialize()` throws exception
  - Expected: Exception caught, error returned, no tokens credited

---

### 11. Data Integrity and Constraints

#### 11.1 Regular Cases
- ✅ **Payment `transaction_id` uniqueness**
  - Two payments with same `transaction_id`
  - Expected: Second webhook updates existing payment (not creates duplicate)

- ✅ **TokenTransaction `provider_transaction_id` uniqueness per tenant**
  - Same `provider_transaction_id` for different tenants
  - Expected: Both transactions created (scoped by `tenant_id`)

#### 11.2 Edge Cases
- ❌ **Payment `purchase_id` foreign key violation**
  - Payload: `purchase_id: 99999` (non-existent)
  - Expected: 404 before FK check, or FK error if purchase deleted mid-process

- ❌ **TokenTransaction `provider_transaction_id` duplicate within tenant**
  - Two webhooks with same `transaction_id` for same tenant
  - Expected: Second webhook idempotent (no duplicate transaction)

- ⚠️ **Metadata JSON validation**
  - Payload: `metadata: "invalid json"`
  - Expected: Should be cast to array or null (check Laravel casting)

---

### 12. Response Format

#### 12.1 Regular Cases
- ✅ **Successful webhook response**
  - Expected: JSON with `received: true`, `purchase_id`, `payment_id`, `status`, `credited_tokens: true/false`

- ✅ **Error response format**
  - Expected: JSON error response with appropriate status code and message

#### 12.2 Edge Cases
- ⚠️ **Response includes correct `credited_tokens` flag**
  - Successful payment with tokens: `credited_tokens: true`
  - Successful payment without tokens: `credited_tokens: false`
  - Failed payment: `credited_tokens: false`

---

### 13. Integration Scenarios

#### 13.1 End-to-End Flows
- ✅ **Complete purchase → payment → token credit flow**
  1. Purchase created via API
  2. Payment webhook received
  3. Payment recorded
  4. Tokens credited
  5. Verify wallet balance

- ✅ **Multiple purchases for same user**
  - User makes multiple purchases
  - Each webhook credits tokens independently
  - Expected: Wallet balance accumulates correctly

- ✅ **Multiple purchases for different users (same tenant pool)**
  - Two users in same pool DB
  - Each has separate wallet and transactions
  - Expected: No cross-user token leakage

#### 13.2 Edge Cases
- ⚠️ **Purchase created but webhook never arrives**
  - Purchase `status: "pending"`
  - Expected: No tokens credited (manual intervention needed)

- ⚠️ **Webhook arrives before purchase creation**
  - Webhook with `purchase_id` that doesn't exist yet
  - Expected: 404 error, webhook can be retried later

---

### 14. Security and Validation

#### 14.1 Regular Cases
- ✅ **Webhook signature verification**
  - Current: Implemented via `PAYMENT_WEBHOOK_SECRET` + `X-Payment-Signature`
  - Expected: Reject invalid signatures before processing

- ✅ **Provider payload parsing (TODO)**
  - Current: Generic request input parsing
  - Expected: Should parse Stripe/Paddle/etc. specific formats

#### 14.2 Edge Cases
- ❌ **Malicious webhook (invalid signature)**
  - Webhook with invalid signature
  - Expected: Should reject before processing (when implemented)

- ❌ **SQL injection attempts**
  - Payload: `purchase_id: "1; DROP TABLE purchases; --"`
  - Expected: Cast to int prevents injection, or validation rejects

- ❌ **XSS in metadata**
  - Payload: `metadata: {"description": "<script>alert('xss')</script>"}`
  - Expected: Stored as JSON, not executed (frontend should sanitize)

---

## Test Implementation Notes

### Test Structure
- **Unit Tests**: Test individual methods (`isPaymentSuccessful()`, validation logic)
- **Feature Tests**: Test full webhook flow with database interactions
- **Integration Tests**: Test cross-DB operations, tenancy lifecycle

### Test Data Setup
- Create test users, tenants, purchases in `setUp()`
- Use database transactions for isolation (`RefreshDatabase` trait)
- Mock external dependencies (payment providers) if needed

### Test Assertions
- Verify database state (record existence, field values)
- Verify response format and status codes
- Verify idempotency (multiple calls produce same result)
- Verify tenancy cleanup (no lingering tenancy context)

### Test Files to Create
1. `backend/tests/Unit/PaymentWebhookControllerTest.php` - Unit tests for helper methods
2. `backend/tests/Feature/PaymentWebhookTest.php` - Feature tests for webhook flow
3. `backend/tests/Feature/TokenCreditingTest.php` - Tests for token crediting logic
4. `backend/tests/Feature/PaymentIdempotencyTest.php` - Tests for idempotency behavior

---

## Priority Levels

### 🔴 Critical (Must Have)
- Webhook input validation
- Purchase resolution
- Payment creation/updates
- Token crediting (regular flow)
- Idempotency (duplicate webhooks)
- Tenant resolution
- Cross-DB transaction integrity

### 🟡 Important (Should Have)
- Payment status handling (all statuses)
- Purchase status updates
- Token wallet edge cases
- Error handling and cleanup
- Response format validation

### 🟢 Nice to Have (Could Have)
- Integration scenarios
- Security validation (signatures)
- Performance tests (concurrent webhooks)
- Load tests (many webhooks)

---

## Test Coverage Goals

- **Line Coverage**: ≥ 90% for `PaymentWebhookController`
- **Branch Coverage**: ≥ 85% (all conditional paths)
- **Edge Case Coverage**: All listed edge cases tested
- **Integration Coverage**: End-to-end flows tested

---

## Notes

- Current implementation has `TODO` for webhook signature verification - this should be implemented and tested
- Token reversal/debit logic is not implemented - consider if needed for refunds
- Concurrent webhook handling may need database-level locking or queue processing
- Consider adding monitoring/logging for failed token credits (manual reconciliation)
