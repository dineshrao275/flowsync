<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Hrms\Employee\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SsoSettings;
use App\Services\TenantLimits;
use App\Support\Hrms\HrmsSchema;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single sign-on via OpenID Connect (F10.2). Public routes by nature — the visitor is not signed in yet — so every one
 * is throttled, tenant-addressed by slug, and fails closed to the login page with a short code, never an exception page.
 * Success goes through the same `establishTenantSession` as a password login: same session, same audit row (`via: sso`).
 * The IdP is trusted for identity, not for access: the user must still be an active member of the tenant (or be created
 * just-in-time on an allowed domain with a non-admin role), and an exited employee is refused exactly like at password login.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly OidcClient $oidc,
        private readonly SsoSettings $settings,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    public function info(string $slug)
    {
        $tenant = $this->tenant($slug);
        $on = $tenant && $this->settings->isUsable($tenant);

        return response()->json(['enabled' => (bool) $on, 'enforce' => $on && $this->settings->get($tenant)['enforce']]);
    }

    public function start(Request $request, string $slug): RedirectResponse
    {
        $tenant = $this->tenant($slug);
        if (! $tenant || ! $this->settings->isUsable($tenant)) {
            return $this->fail('sso_unavailable');
        }

        try {
            $s = $this->settings->get($tenant);
            $discovery = $this->oidc->discover($s['issuer']);
            $state = Str::random(40);
            $nonce = Str::random(40);
            $verifier = Str::random(64);

            $request->session()->put("sso.$state", ['slug' => $slug, 'nonce' => $nonce, 'verifier' => $verifier, 'expires' => now()->addMinutes(10)->timestamp]);

            return redirect()->away($this->oidc->authorizationUrl($discovery, $s['client_id'], $this->redirectUri($slug), $state, $nonce, $verifier));
        } catch (Throwable $e) {
            Log::warning('sso.start_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return $this->fail('sso_unavailable');
        }
    }

    public function callback(Request $request, string $slug): RedirectResponse
    {
        $tenant = $this->tenant($slug);
        $state = (string) $request->query('state');
        $pending = $request->session()->pull("sso.$state");

        if (! $tenant || ! $this->settings->isUsable($tenant) || ! $pending || $pending['slug'] !== $slug || $pending['expires'] < time() || ! $request->query('code')) {
            return $this->fail('sso_failed');
        }

        try {
            $s = $this->settings->get($tenant);
            $discovery = $this->oidc->discover($s['issuer']);
            $jwt = $this->oidc->exchange($discovery, $s['client_id'], (string) $this->settings->secret($tenant), $this->redirectUri($slug), (string) $request->query('code'), $pending['verifier']);
            $claims = $this->oidc->verifyIdToken($jwt, $discovery, $s['client_id'], $pending['nonce']);
        } catch (Throwable $e) {
            Log::warning('sso.callback_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            app(PlatformAudit::class)->auth($request, 'auth.login_failed', ['tenant_id' => $tenant->id, 'reason' => 'sso_token', 'via' => 'sso']);

            return $this->fail('sso_failed');
        }

        $email = Str::lower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ($claims['email_verified'] ?? true) === false || ! $this->domainAllowed($email, $s['allowed_domains'])) {
            app(PlatformAudit::class)->auth($request, 'auth.login_failed', ['tenant_id' => $tenant->id, 'email' => $email, 'reason' => 'sso_email', 'via' => 'sso']);

            return $this->fail('sso_email');
        }

        $outcome = $this->dbm->using($tenant, function () use ($request, $tenant, $email, $claims, $s) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                if (! $s['jit']) {
                    return 'sso_no_account';
                }
                $user = $this->provision($tenant, $email, (string) ($claims['name'] ?? $email), (string) $s['default_role']);
                if ($user === null) {
                    return 'sso_no_account';
                }
            }

            $employee = HrmsSchema::present() ? Employee::query()->where('user_id', $user->id)->first() : null;
            if ($employee?->status?->isOffboarding()) {
                return 'sso_deactivated';
            }

            app(AuthController::class)->establishTenantSession($request, $user, $tenant, 'sso');

            return null;
        });

        if ($outcome !== null) {
            app(PlatformAudit::class)->auth($request, 'auth.login_failed', ['tenant_id' => $tenant->id, 'email' => $email, 'reason' => $outcome, 'via' => 'sso']);

            return $this->fail($outcome);
        }

        return redirect('/app/');
    }

    /** Create the account on first sign-in: a non-admin role, inside the plan's user limit, with its routing row. */
    private function provision(Tenant $tenant, string $email, string $name, string $roleSlug): ?User
    {
        $role = Role::query()->where('slug', $roleSlug)->first();
        if ($role === null || $roleSlug === 'admin') {
            return null; // never auto-create an administrator
        }

        try {
            app(TenantLimits::class)->assertQuota('users');
        } catch (Throwable) {
            return null;
        }

        $user = User::create(['name' => mb_substr($name, 0, 255), 'email' => $email, 'password' => Str::random(48)]);
        $user->roles()->sync([$role->id]);
        TenantUserRouting::updateOrCreate(['tenant_id' => $tenant->id, 'email' => $email], ['user_id' => (int) $user->id, 'name' => $user->name]);

        return $user;
    }

    /** @param list<string> $allowed */
    private function domainAllowed(string $email, array $allowed): bool
    {
        return $allowed === [] || in_array(Str::after($email, '@'), array_map('strtolower', $allowed), true);
    }

    private function tenant(string $slug): ?Tenant
    {
        $this->dbm->connectSystem();
        $tenant = Tenant::query()->where('slug', $slug)->first();

        return $tenant && $tenant->isServiceable() ? $tenant : null;
    }

    private function redirectUri(string $slug): string
    {
        return url("/api/sso/{$slug}/callback");
    }

    private function fail(string $code): RedirectResponse
    {
        return redirect('/app/login?sso_error='.$code);
    }
}
