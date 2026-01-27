<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends BaseController
{
    /**
     * Webhook entrypoint (central domain).
     *
     * Tenant context is initialized by request data (e.g. X-Tenant header) before this runs.
     */
    public function handle(Request $request): JsonResponse
    {
        // Placeholder: verify signatures + parse provider payloads (Stripe/Paddle/etc).
        // The key contract here is that tenant() is initialized and the tenant DB connection
        // is routed to the correct pool before any tenant-private writes.

        return $this->sendResponse([
            'received' => true,
            'tenant_id' => tenant('id'),
        ], 'Webhook received');
    }
}

