<?php

namespace App\Http\Middleware;

use App\Models\ComfyUiWorker;
use Closure;
use Illuminate\Http\Request;

class EnsureWorkerToken
{
    public function handle(Request $request, Closure $next)
    {
        $provided = $request->bearerToken() ?: (string) $request->header('X-Worker-Token');
        if ($provided === '') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        // 1. Per-worker token auth: hash provided token and look up in comfy_ui_workers
        $tokenHash = hash('sha256', $provided);
        $worker = ComfyUiWorker::query()->where('token_hash', $tokenHash)->first();

        if (!$worker) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (!$worker->is_approved) {
            return response()->json([
                'success' => false,
                'message' => 'Worker not approved.',
            ], 401);
        }

        $request->attributes->set('authenticated_worker', $worker);
        return $next($request);
    }
}
