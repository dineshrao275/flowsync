<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\ImpersonationLog;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Auth\SessionPayload;
use App\Services\PlatformAudit;
use App\Services\Security\TwoFactorLogin;
use App\Services\Sso\SsoSettings;
use App\Support\Hrms\HrmsSchema;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        return $this->loginIsolated($request, $credentials);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->session()->get('impersonate.tenant_id')
            ?? $request->session()->get('login.tenant_id');

        $this->closeOpenImpersonation($request);

        app(TenantDatabaseManager::class)->connectSystem();

        app(PlatformAudit::class)->auth($request, 'auth.logout', [
            'email' => $user?->email,
            'user_id' => $user?->id,
            'tenant_id' => $tenantId,
        ], $user !== null && $user->is_super_admin ? (int) $user->id : null);

        Log::info("User logged out: {$user?->email}", [
            'user_id' => $user?->id,
            'tenant_id' => $tenantId,
            'ip' => $request->ip(),
        ]);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('Logged out.')]);
    }

    public function me(Request $request): JsonResponse
    {
        $this->applyTenantContext($request->user());

        return response()->json(app(SessionPayload::class)->build($request));
    }

    /**
     * Authenticate a tenant user against their tenant DB and build the session
     * (used by self-registration auto-login). The whole payload build runs inside
     * `using($tenant)` so `$user->load('roles')` resolves against THAT tenant's
     * database — after the block the default connection is restored. `$via`
     * labels the session's `auth.login` row (password vs registration).
     */
    public function establishTenantSession(Request $request, User $user, Tenant $tenant, string $via = 'password'): JsonResponse
    {
        return app(TenantDatabaseManager::class)->using($tenant, function () use ($request, $user, $tenant, $via): JsonResponse {
            Auth::login($user);

            $request->session()->put('login.tenant_id', $tenant->id);
            $request->session()->forget('impersonate');

            $this->applyTenantContext($request->user());

            $request->session()->regenerate();

            app(PlatformAudit::class)->auth($request, 'auth.login', [
                'email' => $user->email,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'via' => $via,
            ]);

            return response()->json(app(SessionPayload::class)->build($request));
        });
    }

    /**
     * Isolated login: resolve the target tenant via the central tenant_users
     * routing index, then authenticate against that tenant's database.
     * Super admins (no routing row) authenticate against the system database.
     */
    private function loginIsolated(Request $request, array $credentials): JsonResponse
    {
        $dbm = app(TenantDatabaseManager::class);
        $dbm->connectSystem();

        $email = Str::lower(trim((string) $credentials['email']));
        $routes = TenantUserRouting::where('email', $email)->with('tenant')->get();

        if ($tenantSlug = $request->input('tenant')) {
            $routes = $routes->filter(fn ($route) => $route->tenant?->slug === $tenantSlug)->values();
        }

        if ($routes->isEmpty()) {
            // Super admin (system users) or unknown email.
            if (Auth::attempt(Arr::except($credentials, ['tenant']), $request->boolean('remember'))) {
                if ($challenge = app(TwoFactorLogin::class)->challengeIfNeeded($request, Auth::user(), null)) {
                    return $challenge;
                }

                $request->session()->forget('login.tenant_id');
                $request->session()->forget('impersonate');
                $request->session()->regenerate();

                app(PlatformAudit::class)->auth($request, 'auth.login', [
                    'email' => $email,
                    'user_id' => Auth::id(),
                    'via' => 'password',
                ]);

                Log::info("Super Admin logged in: {$email}", [
                    'user_id' => Auth::id(),
                    'ip' => $request->ip(),
                ]);

                return response()->json(app(SessionPayload::class)->build($request));
            }

            app(PlatformAudit::class)->auth($request, 'auth.login_failed', [
                'email' => $email,
                'via' => 'password',
            ]);

            Log::warning("Failed login attempt for: {$email}", [
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($routes->count() > 1) {
            throw ValidationException::withMessages([
                'tenant' => __('This email belongs to more than one tenant. Provide your tenant slug.'),
            ]);
        }

        $tenant = $routes->first()->tenant;

        if (! $tenant || ! $tenant->canSignIn()) {
            app(PlatformAudit::class)->auth($request, 'auth.login_failed', [
                'email' => $email,
                'tenant_id' => $tenant?->id,
                'reason' => 'tenant_unavailable',
                'via' => 'password',
            ]);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return $dbm->using($tenant, function () use ($request, $credentials, $tenant, $email) {
            if (! Auth::attempt(Arr::except($credentials, ['tenant']), $request->boolean('remember'))) {
                app(PlatformAudit::class)->auth($request, 'auth.login_failed', [
                    'email' => $email,
                    'tenant_id' => $tenant->id,
                    'via' => 'password',
                ]);

                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }

            // F10.2: when the tenant enforces single sign-on, a password only works for the protected default user
            // (the break-glass account); everyone else must use the identity provider.
            if (app(SsoSettings::class)->get($tenant)['enforce'] && ! Auth::user()->is_default) {
                Auth::logout();

                app(PlatformAudit::class)->auth($request, 'auth.login_failed', [
                    'email' => $email,
                    'tenant_id' => $tenant->id,
                    'reason' => 'sso_required',
                    'via' => 'password',
                ]);

                throw ValidationException::withMessages(['email' => __('This workspace requires single sign-on. Use the SSO button on the sign-in page.')]);
            }

            // H-3: valid credentials are not enough — the employment record
            // decides. A leaver keeps a working password; only the offboarding
            // statuses revoke the session. Suspended/on-notice staff are still
            // employed and keep access.
            $employee = HrmsSchema::present() ? Employee::where('user_id', Auth::id())->first() : null;

            if ($employee?->status?->isOffboarding()) {
                Auth::logout();

                app(PlatformAudit::class)->auth($request, 'auth.login_failed', [
                    'email' => $email,
                    'tenant_id' => $tenant->id,
                    'reason' => 'employee_exited',
                    'via' => 'password',
                ]);

                throw ValidationException::withMessages(['email' => __('This account has been deactivated.')]);
            }

            if ($challenge = app(TwoFactorLogin::class)->challengeIfNeeded($request, Auth::user(), $tenant)) {
                return $challenge;
            }

            $request->session()->put('login.tenant_id', $tenant->id);
            $request->session()->forget('impersonate');

            $this->applyTenantContext($request->user());

            $request->session()->regenerate();

            app(PlatformAudit::class)->auth($request, 'auth.login', [
                'email' => $email,
                'user_id' => Auth::id(),
                'tenant_id' => $tenant->id,
                'via' => 'password',
            ]);

            Log::info("Tenant user logged in: {$email} [tenant: {$tenant->slug}#{$tenant->id}]", [
                'user_id' => Auth::id(),
                'tenant_id' => $tenant->id,
                'ip' => $request->ip(),
            ]);

            return response()->json(app(SessionPayload::class)->build($request));
        });
    }

    private function applyTenantContext(User $user): void
    {
        $context = app(TenantContext::class);

        if ($user->is_super_admin) {
            $context->setTenantId(null);
            $context->setImpersonating(false);

            return;
        }

        $session = request()->session();
        $impersonation = $session->get('impersonate');

        $context->setTenantId($impersonation['tenant_id'] ?? $session->get('login.tenant_id'));
        $context->setImpersonating($impersonation !== null);
    }

    private function closeOpenImpersonation(Request $request): void
    {
        $impersonation = $request->session()->get('impersonate');

        if (! $impersonation) {
            return;
        }

        $log = ImpersonationLog::find($impersonation['log_id']);
        if ($log && ! $log->ended_at) {
            $log->update(['ended_at' => now()]);
        }
    }
}
