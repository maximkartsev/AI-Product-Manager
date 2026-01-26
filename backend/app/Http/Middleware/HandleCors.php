<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array $config */
        $config = config('cors', []);

        $paths = (array) ($config['paths'] ?? []);
        $matchesPath = !empty($paths) ? $request->is(...$paths) : false;

        $allowedMethods = (array) ($config['allowed_methods'] ?? ['*']);
        $allowedHeaders = (array) ($config['allowed_headers'] ?? ['*']);
        $exposedHeaders = (array) ($config['exposed_headers'] ?? []);
        $supportsCredentials = (bool) ($config['supports_credentials'] ?? false);
        $maxAge = $config['max_age'] ?? 0;
        
        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS') && $matchesPath) {
            $origin = $request->header('Origin');
            
            // Check if origin is allowed
            if ($origin && $this->isOriginAllowed($origin, $config)) {
                $methods = $allowedMethods === ['*']
                    ? 'GET, POST, PUT, DELETE, PATCH, OPTIONS' 
                    : implode(', ', $allowedMethods);
                
                $headers = $allowedHeaders === ['*']
                    ? 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN'
                    : implode(', ', $allowedHeaders);
                
                return response('', 200)
                    ->header('Vary', 'Origin')
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', $methods)
                    ->header('Access-Control-Allow-Headers', $headers)
                    ->header('Access-Control-Allow-Credentials', $supportsCredentials ? 'true' : 'false')
                    ->header('Access-Control-Max-Age', (string) $maxAge);
            }
        }

        $response = $next($request);

        // Add CORS headers to the response for API routes
        if ($matchesPath) {
            $origin = $request->header('Origin');
            
            if ($origin && $this->isOriginAllowed($origin, $config)) {
                $methods = $allowedMethods === ['*']
                    ? 'GET, POST, PUT, DELETE, PATCH, OPTIONS' 
                    : implode(', ', $allowedMethods);
                
                $headers = $allowedHeaders === ['*']
                    ? 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN, X-XSRF-TOKEN'
                    : implode(', ', $allowedHeaders);
                
                $response->headers->set('Vary', 'Origin');
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', $supportsCredentials ? 'true' : 'false');
                $response->headers->set('Access-Control-Allow-Methods', $methods);
                $response->headers->set('Access-Control-Allow-Headers', $headers);
                
                if (!empty($exposedHeaders)) {
                    $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
                }
            }
        }

        return $response;
    }

    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed(string $origin, array $config): bool
    {
        // Check exact matches
        if (in_array($origin, (array) ($config['allowed_origins'] ?? []), true)) {
            return true;
        }

        // Check patterns
        foreach ((array) ($config['allowed_origins_patterns'] ?? []) as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }
}
