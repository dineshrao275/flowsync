<?php

namespace App\Providers;

use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantDatabaseManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('permission', function (User $user, string $permission) {
            if ($user->is_super_admin) {
                return true;
            }

            return $user->hasPermission($permission);
        });
    }
}
