<?php

namespace App\Http\Controllers;

use App\Models\ImpersonationLog;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'tenant' => ['sometimes', 'nullable', 'string', 'alpha_dash'],
        ]);

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
                'impersonating' => $impersonation !== null,
                'impersonated_by' => $impersonation['original_user_id'] ?? null,
            ],
            'theme' => $isSuperAdmin
                ? config('theme.defaults')
                : ($user->settings?->settings['theme'] ?? config('theme.defaults')),
        ];
    }
}