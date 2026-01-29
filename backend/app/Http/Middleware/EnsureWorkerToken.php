<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureWorkerToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('services.comfyui.worker_token');
        $provided = $request->bearerToken() ?: (string) $request->header('X-Worker-Token');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
