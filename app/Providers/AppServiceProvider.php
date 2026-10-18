<?php

namespace App\Providers;

use App\Billing\PaymentResolver;
use App\Billing\PaymentService;
use App\Billing\Stripe\StripeClient;
use App\Contracts\Notifications\NotificationSender;
use App\Http\Middleware\EnsurePermission;
use App\Jobs\SyncStripeCustomer;
use App\Listeners\SwitchesTenantConnectionForQueuedJobs;
use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureFlags;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\ReportsTo;
use App\Services\Security\PlatformAccess;
use App\Services\TenantLimits;
use App\Support\Hrms\HrmsSchema;
use App\Support\Integration\BridgeSchema;
use App\Support\RequestId;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use App\Support\Tms\TmsSchema;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fresh per resolution so a runtime change of the Stripe key (tests, `config:cache` refresh) is honoured.
        $this->app->bind(StripeClient::class, fn () => new StripeClient((string) config('payments.gateways.stripe.secret', '')));

        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantDatabaseManager::class);
        $this->app->singleton(PaymentResolver::class, function ($app) {
            return new PaymentResolver(config('payments', []));
        });
        $this->app->singleton(PaymentService::class);
        $this->app->bind(NotificationSender::class, NotificationDispatcher::class);
        // Per request / queued job: flag state must not outlive an admin edit (P8.7).
        $this->app->scoped(FeatureFlags::class);
        $this->app->scoped(PlatformAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The tenant's Stripe customer carries the organisation's name and billing email: keep it in step.
        Tenant::updated(function (Tenant $tenant): void {
            if ($tenant->billing_customer_id && $tenant->wasChanged(['name', 'billing_email'])) {
                try {
                    SyncStripeCustomer::dispatch($tenant)->afterCommit();
                } catch (\Throwable $e) { // best effort: with a sync queue a Stripe outage must not break saving the tenant
                    Log::warning('Stripe customer sync failed.', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
                }
            }
        });

        // Private/presence channel authorization callbacks. Must be registered on every
        // boot, independent of the route cache (see the note above Broadcast::routes()).
        require base_path('routes/channels.php');

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

        // Per-token throttle for /api/v1: the token's own limit, else the configured default.
        RateLimiter::for('api-token', function (Request $request) {
            $token = $request->attributes->get('api_token');
            $limit = $token instanceof ApiToken
                ? min((int) ($token->rate_limit ?: config('api.default_rate_limit')), (int) config('api.max_rate_limit'))
                : (int) config('api.default_rate_limit');

            return Limit::perMinute($limit)->by('api-token:'.($token?->id ?? $request->ip()));
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
     * The schema probes (TMS/HRMS/bridge) ride the same boundary: enabling a
     * product must flip the next `present()` answer, not the next recycle.
     */
    private function registerTenantLimitsMemoBoundaries(): void
    {
        Event::listen(RequestHandled::class, function (): void {
            TenantLimits::resetMemo();
            ReportsTo::resetMemo();
            TmsSchema::flush();
            HrmsSchema::flush();
            BridgeSchema::flush();
        });
        Event::listen(JobProcessing::class, function (): void {
            TenantLimits::resetMemo();
            ReportsTo::resetMemo();
            TmsSchema::flush();
            HrmsSchema::flush();
            BridgeSchema::flush();
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

            // Tenant + the originating request's correlation id travel with the job.
            return array_filter([
                'tenant_id' => is_int($tenantId) ? $tenantId : null,
                'request_id' => RequestId::current(),
            ], fn ($v) => $v !== null);
        });

        $listener = $this->app->make(SwitchesTenantConnectionForQueuedJobs::class);

        Event::listen(JobProcessing::class, [$listener, 'handleProcessing']);
        Event::listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class], [$listener, 'handleFinished']);
    }
}
