<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    /**
     * Ensure the authenticated user is an admin.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!(bool) ($user->is_admin ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access required.',
            ], 403);
        }

        return $next($request);
    }
}
