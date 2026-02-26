<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API middleware ordering matters:
        // - CORS must be handled in the API stack for browser integration.
        // - ForceJsonResponse ensures Laravel treats API requests as JSON.
        $middleware->api(prepend: [
            \App\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tell Laravel to always render JSON for AuthenticationException
        // This prevents Laravel's default handler from trying to redirect to route('login')
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            // Always return JSON for API requests and AuthenticationException
            if ($e instanceof AuthenticationException) {
                return true;
            }
            return $request->expectsJson() || $request->is('api/*');
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
        
        // Global catch-all for unhandled exceptions on API routes.
        // Returns a user-friendly JSON error and logs the full trace.
        $exceptions->render(function (\Throwable $e, $request) {
            if ($e instanceof AuthenticationException) {
                return null; // handled above
            }
            if ($e instanceof ValidationException) {
                return null; // let Laravel render standard 422 validation response
            }
            if ($e instanceof HttpExceptionInterface) {
                return null; // preserve framework HTTP status handling (e.g. 404)
            }
            if ($request->expectsJson() || $request->is('api/*')) {
                \Illuminate\Support\Facades\Log::error('Unhandled exception', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred. Please try again or contact support.',
                ], 500);
            }
        });

        // Set redirect callback to return empty string (not null) for all routes
        // Returning null causes Laravel to fallback to route('login')
        // Empty string prevents the fallback
        \Illuminate\Auth\AuthenticationException::redirectUsing(function ($request) {
            return '';
        });
    })->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('workers:publish-metrics')->everyMinute();
        $schedule->command('workers:cleanup-stale')->everyFifteenMinutes();
    })->booted(function () {
        RateLimiter::for('fleet-register', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    })->create();
