<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Webhook-style tenant identification uses request data (header), not domain.
        // Used for external callbacks (e.g. payments) which won't be sent to tenant subdomains.
        InitializeTenancyByRequestData::$header = 'X-Tenant';
        InitializeTenancyByRequestData::$queryParameter = null;
    }
}
