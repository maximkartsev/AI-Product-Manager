<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonResponse
{
    /**
     * Force JSON responses for API routes.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*')) {
            // Ensure Laravel treats API requests as JSON even when Accept: */*
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}

