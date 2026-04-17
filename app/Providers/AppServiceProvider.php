<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('create-export', function (Request $request) {
            return Limit::perMinute((int) env('EXPORT_CREATE_RATE_PER_MINUTE', 8))->by($request->ip());
        });

        RateLimiter::for('download-export', function (Request $request) {
            return Limit::perMinute((int) env('EXPORT_DOWNLOAD_RATE_PER_MINUTE', 60))->by($request->ip());
        });
    }
}
