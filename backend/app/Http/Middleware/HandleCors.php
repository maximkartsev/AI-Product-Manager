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
        $config = config('cors');
        
        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            $origin = $request->header('Origin');
            
            // Check if origin is allowed
            if ($origin && $this->isOriginAllowed($origin, $config)) {
                $methods = $config['allowed_methods'] === ['*'] 
                    ? 'GET, POST, PUT, DELETE, PATCH, OPTIONS' 
                    : implode(', ', $config['allowed_methods']);
                
                $headers = $config['allowed_headers'] === ['*']
                    ? 'Content-Type, Authorization, X-Requested-With, Accept, Origin'
                    : implode(', ', $config['allowed_headers']);
                
                return response('', 200)
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', $methods)
                    ->header('Access-Control-Allow-Headers', $headers)
                    ->header('Access-Control-Allow-Credentials', $config['supports_credentials'] ? 'true' : 'false')
                    ->header('Access-Control-Max-Age', (string) $config['max_age']);
            }
        }

        $response = $next($request);

        // Add CORS headers to the response for API routes
        if ($request->is($config['paths'])) {
            $origin = $request->header('Origin');
            
            if ($origin && $this->isOriginAllowed($origin, $config)) {
                $methods = $config['allowed_methods'] === ['*'] 
                    ? 'GET, POST, PUT, DELETE, PATCH, OPTIONS' 
                    : implode(', ', $config['allowed_methods']);
                
                $headers = $config['allowed_headers'] === ['*']
                    ? 'Content-Type, Authorization, X-Requested-With, Accept, Origin'
                    : implode(', ', $config['allowed_headers']);
                
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', $config['supports_credentials'] ? 'true' : 'false');
                $response->headers->set('Access-Control-Allow-Methods', $methods);
                $response->headers->set('Access-Control-Allow-Headers', $headers);
                
                if (!empty($config['exposed_headers'])) {
                    $response->headers->set('Access-Control-Expose-Headers', implode(', ', $config['exposed_headers']));
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
        if (in_array($origin, $config['allowed_origins'])) {
            return true;
        }

        // Check patterns
        foreach ($config['allowed_origins_patterns'] as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }
}
