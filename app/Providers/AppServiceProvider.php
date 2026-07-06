<?php

namespace App\Providers;

use App\Contracts\SecretProvider;
use App\Services\Secrets\SecretProviderManager;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SecretProvider::class, fn () => new SecretProviderManager());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ENV-CACHE-DRIFT-BATCH: config-cache-safe (env() in a provider returns null
        // once config is cached).
        if (config('app.env') === 'production' && filter_var(config('xdr.force_https', false), FILTER_VALIDATE_BOOL)) {
            URL::forceScheme('https');
        }
    }
}
