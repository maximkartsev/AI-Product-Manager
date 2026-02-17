<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // For API routes or JSON requests, return null to prevent redirect
        // This will allow the unauthenticated method to handle it properly
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // For web routes, return null since we don't have a login route
        // The unauthenticated method will handle the exception
        return null;
    }

    /**
     * Handle unauthenticated user for API routes.
     */
    protected function unauthenticated($request, array $guards)
    {
        // Always throw AuthenticationException with null redirect path
        // This prevents Laravel from trying to generate any redirect URLs
        throw new AuthenticationException(
            'Unauthenticated.', $guards, null
        );
    }
}
