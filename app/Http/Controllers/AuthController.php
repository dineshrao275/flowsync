<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\ImpersonationLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\TenantLimits;
use App\Services\TenantOnboarding;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use App\Support\ThemeMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
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
        $this->closeOpenImpersonation($request);

        app(TenantDatabaseManager::class)->connectSystem();

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $this->applyTenantContext($request->user());

        return response()->json($this->payload($request));
    }

    /**
     * Authenticate a tenant user against their tenant DB and build the session
     * (used by self-registration auto-login). The whole payload build runs inside
     * `using($tenant)` so `$user->load('roles')` resolves against THAT tenant's
     * database — after the block the default connection is restored.
     */
    public function establishTenantSession(Request $request, User $user, Tenant $tenant): JsonResponse
    {
        return app(TenantDatabaseManager::class)->using($tenant, function () use ($request, $user, $tenant): JsonResponse {
            Auth::login($user);

            $request->session()->put('login.tenant_id', $tenant->id);
            $request->session()->forget('impersonate');

            $this->applyTenantContext($request->user());

            $request->session()->regenerate();

            return response()->json($this->payload($request));
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
                $request->session()->forget('login.tenant_id');
                $request->session()->forget('impersonate');
                $request->session()->regenerate();

                return response()->json($this->payload($request));
            }

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($routes->count() > 1) {
            throw ValidationException::withMessages([
                'tenant' => 'This email belongs to more than one tenant. Provide your tenant slug.',
            ]);
        }

        $tenant = $routes->first()->tenant;

        if (! $tenant || ! $tenant->isServiceable()) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return $dbm->using($tenant, function () use ($request, $credentials, $tenant) {
            if (! Auth::attempt(Arr::except($credentials, ['tenant']), $request->boolean('remember'))) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }

            $request->session()->put('login.tenant_id', $tenant->id);
            $request->session()->forget('impersonate');

            $this->applyTenantContext($request->user());

            $request->session()->regenerate();

            return response()->json($this->payload($request));
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

    private function payload(Request $request): array
    {
        $user = $request->user();
        $impersonation = $request->session()->get('impersonate');

        $isSuperAdmin = (bool) $user->is_super_admin;

        if (! $isSuperAdmin) {
            $user->load(['roles', 'roles.permissions', 'settings']);
        }

        $tenantId = $impersonation['tenant_id'] ?? $request->session()->get('login.tenant_id');
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $isSuperAdmin,
                'tenant' => $tenant?->only(['id', 'name', 'slug']),
                'roles' => $isSuperAdmin ? [] : $user->roleSlugs(),
                'permissions' => $isSuperAdmin ? [] : $user->permissionSlugs(),
                'modules' => $tenant
                    ? (app(TenantLimits::class)->limit($tenant, 'modules') ?? config('subscriptions.modules'))
                    : config('subscriptions.modules'),
                'impersonating' => $impersonation !== null,
                'impersonated_by' => $impersonation['original_user_id'] ?? null,
                'onboarding_complete' => ! $tenant || app(TenantOnboarding::class)->isComplete($tenant),
            ],
            // `mode` (light/dark/system) sits next to the colors but is not part
            // of theme.defaults, so it is merged in explicitly. A platform super
            // admin must never touch the tenant-only `settings` relation.
            'theme' => array_merge(
                $isSuperAdmin
                    ? array_merge(config('theme.defaults'), $this->platformTheme($user))
                    : ($user->settings?->settings['theme'] ?? config('theme.defaults')),
                ['mode' => $isSuperAdmin
                    ? ThemeMode::resolve($this->platformTheme($user)['mode'] ?? null)
                    : ThemeMode::resolve($user->settings?->settings['theme']['mode'] ?? null)],
            ),
        ];
    }

    /**
     * A platform super admin's stored theme lives in the central key-value
     * settings (see ThemeController), never in the tenant `settings` relation.
     *
     * @return array<string, mixed>
     */
    private function platformTheme(object $user): array
    {
        $raw = PlatformSetting::value('theme.user.'.$user->id);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
