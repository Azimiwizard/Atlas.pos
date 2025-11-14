<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\StoreManager;
use App\Services\TenantManager;
use App\Support\Environment\EnvironmentHealth;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
        $this->app->singleton(StoreManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(EnvironmentHealth $health): void
    {
        $health->applyRuntimeFallbacks();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
