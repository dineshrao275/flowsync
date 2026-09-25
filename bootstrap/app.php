<?php

use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\SwitchTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // NOTE: `channels` is intentionally NOT registered here. Laravel would
        // then register /broadcasting/auth with the bare `web` group, so the
        // session user would be resolved on the default (system) connection
        // where tenant users don't exist -> channel auth always 403s. It is
        // registered in routes/web.php with `switch_tenant` instead.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'signed' => ValidateSignature::class,
            'switch_tenant' => SwitchTenant::class,
            'tenant' => SetTenantContext::class,
            'tenant_context' => EnsureTenantContext::class,
            'super_admin' => EnsureSuperAdmin::class,
            'onboarding_complete' => EnsureOnboardingComplete::class,
            'ensure_module' => EnsureModule::class,
        ]);

        // Middleware priority (Laravel SortedMiddleware) — the framework sorts the
        // route middleware by this map, so any custom middleware that must run
        // BEFORE route-model binding (SwitchTenant/tenant context/authorship) has to
        // be listed here, ahead of SubstituteBindings. Without this, binding runs
        // against whatever the previous request left as the default connection
        // (the central DB in isolated/RTL mode), producing "no such table" 500s.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SwitchTenant::class,
            Authenticate::class,
            SetTenantContext::class,
            EnsureTenantContext::class,
            EnsureSuperAdmin::class,
            EnsurePermission::class,
            EnsureOnboardingComplete::class,
            EnsureModule::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
