<?php

namespace App\Providers;

use App\Billing\PaymentResolver;
use App\Billing\PaymentService;
use App\Http\Middleware\EnsurePermission;
use App\Listeners\SwitchesTenantConnectionForQueuedJobs;
use App\Models\User;
use App\Services\ReportsTo;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantDatabaseManager::class);
        $this->app->singleton(PaymentResolver::class, function ($app) {
            return new PaymentResolver(config('payments', []));
        });
        $this->app->singleton(PaymentService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A denied gate answers with the SAME sentence the route middleware
        // uses, so `Gate::authorize('permission', …)` and a `permission:…`
        // route gate can never word a refusal differently. `Gate::inspect`
        // understands a `Response` result, so `check`/`allows` are unaffected.
        Gate::define('permission', function (User $user, string $permission) {
            if ($user->is_super_admin && ! app(TenantContext::class)->impersonating()) {
                return true;
            }

            return $user->hasPermission($permission)
                ? true
                : Response::deny(EnsurePermission::denial($permission));
        });

        $this->registerQueuedJobTenantContext();
        $this->registerTenantLimitsMemoBoundaries();
        $this->assertInfrastructureConnectionsPinned();
    }

    /**
     * `TenantLimits::effective()` memoizes per unit of work — one HTTP request,
     * or one queued job. Without these flushes the static memo would outlive
     * the request in a long-lived worker (Octane, queue daemons) and a plan or
     * `limits_override` edit would stay invisible until the process recycled.
     * A plan edit must take effect on the very next read; `ModuleGateTest`
     * (grants a module, expects the next request to allow it) is the guard.
     */
    private function registerTenantLimitsMemoBoundaries(): void
    {
        Event::listen(RequestHandled::class, function (): void {
            TenantLimits::resetMemo();
            ReportsTo::resetMemo();
        });
        Event::listen(JobProcessing::class, function (): void {
            TenantLimits::resetMemo();
            ReportsTo::resetMemo();
        });
    }

    /**
     * Sessions, cache, and jobs live on the central DB. When those drivers
     * are `database` and the connection is left null, Laravel falls through
     * to the request's default connection — which SwitchTenant points at the
     * tenant DB. Production must pin them (SESSION_CONNECTION=system, etc.).
     */
    private function assertInfrastructureConnectionsPinned(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $pins = [
            'session' => config('session.driver') === 'database' ? config('session.connection') : 'ok',
            'cache' => config('cache.default') === 'database' ? config('cache.stores.database.connection') : 'ok',
            'queue' => config('queue.default') === 'database' ? config('queue.connections.database.connection') : 'ok',
        ];

        $missing = array_keys(array_filter($pins, fn ($value) => $value === null || $value === ''));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'Production database session/cache/queue connections must be pinned to the central connection (system). Unset: '.implode(', ', $missing).'. See .env.example.',
        );
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
