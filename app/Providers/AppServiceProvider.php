<?php

namespace App\Providers;

use App\Listeners\SwitchesTenantConnectionForQueuedJobs;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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

        $this->registerQueuedJobTenantContext();
    }

    /**
     * Isolated tenancy + queueing: a worker runs without a session, so it has to
     * be told which tenant database a job belongs to. The tenant id is stamped
     * onto the job payload here and consumed by the listener below before the
     * job's command is unserialized (SerializesModels re-queries models at that
     * point, so the connection has to be connected first).
     *
     * @see SwitchesTenantConnectionForQueuedJobs
     */
    private function registerQueuedJobTenantContext(): void
    {
        Queue::createPayloadUsing(function (): array {
            $tenantId = $this->app->make(TenantContext::class)->currentId();

            return is_int($tenantId) ? ['tenant_id' => $tenantId] : [];
        });

        $listener = $this->app->make(SwitchesTenantConnectionForQueuedJobs::class);

        Event::listen(JobProcessing::class, [$listener, 'handleProcessing']);
        Event::listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class], [$listener, 'handleFinished']);
    }
}
