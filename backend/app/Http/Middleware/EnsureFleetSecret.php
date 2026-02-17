<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureFleetSecret
{
    public function handle(Request $request, Closure $next)
    {
        $configured = (string) config('services.comfyui.fleet_secret');
        if ($configured === '') {
            return response()->json([
                'success' => false,
                'message' => 'Fleet registration is not configured.',
            ], 503);
        }

        $provided = (string) $request->header('X-Fleet-Secret');
        if (!hash_equals($configured, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
