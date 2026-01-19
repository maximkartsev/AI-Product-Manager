<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Force JSON for all API routes so unauthenticated returns JSON, not redirect
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tell Laravel to always render JSON for AuthenticationException
        // This prevents Laravel's default handler from trying to redirect to route('login')
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            // Always return JSON for AuthenticationException to prevent redirects
            if ($e instanceof AuthenticationException) {
                return true;
            }
            return false;
        });
        
        // Handle AuthenticationException and ALWAYS return JSON response
        // This MUST return a response to prevent Laravel's default handler
        // from trying to redirect to route('login')
        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        });
        
        // Set redirect callback to return empty string (not null) for all routes
        // Returning null causes Laravel to fallback to route('login')
        // Empty string prevents the fallback
        \Illuminate\Auth\AuthenticationException::redirectUsing(function ($request) {
            return '';
        });
    })->create();
