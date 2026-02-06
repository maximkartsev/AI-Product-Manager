<?php

namespace App\Http\Controllers;

use App\Models\TokenWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends BaseController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->sendError('Unauthorized.', [], 401);
        }

        $tenant = tenant();
        if (!$tenant) {
            return $this->sendError('Tenant context is required.', [], 400);
        }

        $tenantId = (string) $tenant->getKey();
        $wallet = TokenWallet::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['user_id' => $user->id, 'balance' => 0]
        );

        if ((int) $wallet->user_id !== (int) $user->id) {
            return $this->sendError('Token wallet user mismatch.', [], 500);
        }

        return $this->sendResponse([
            'balance' => (int) $wallet->balance,
        ], 'Wallet retrieved');
    }
}
