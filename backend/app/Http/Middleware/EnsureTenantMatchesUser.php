<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantMatchesUser
{
    /**
     * Ensure the authenticated user belongs to the current tenant.
     *
     * In this project each user maps 1:1 to a tenant. Tenant is resolved by subdomain/domain,
     * and the tenant record stores `user_id` in the central DB.
     */
    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
            ], 400);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $tenantUserId = $tenant->getAttribute('user_id');
        if (!$tenantUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant is not linked to a user.',
            ], 500);
        }

        if ((int) $tenantUserId !== (int) $user->getAuthIdentifier()) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant mismatch.',
            ], 403);
        }

        return $next($request);
    }
}

