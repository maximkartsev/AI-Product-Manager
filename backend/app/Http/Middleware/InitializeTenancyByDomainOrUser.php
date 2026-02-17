<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByDomainOrUser
{
    public function handle(Request $request, Closure $next)
    {
        $tenancy = app(Tenancy::class);
        $tenant = $this->resolveTenant($request);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
            ], 400);
        }

        $tenancy->initialize($tenant);

        try {
            return $next($request);
        } finally {
            $tenancy->end();
        }
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        $host = $request->getHost();
        if ($host) {
            $domain = Domain::query()->where('domain', $host)->first();
            if ($domain && $domain->tenant) {
                return $domain->tenant;
            }
        }

        $user = $request->user();
        if (!$user) {
            return null;
        }

        return Tenant::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->first();
    }
}
