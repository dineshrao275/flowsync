<?php

namespace App\Services\Security;

use App\Http\Controllers\AuthController;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformAudit;
use App\Support\TenantDatabaseManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The second step of sign-in (P8.1).
 *
 * `challengeIfNeeded()` runs right after a correct password: an enrolled
 * account is signed OUT again and parked in a short-lived `2fa.pending` session
 * record (no authenticated session exists until the code is right); a required
 * but not-yet-enrolled account signs in normally but is flagged so the
 * middleware limits it to enrolment. `complete()` verifies the code and then
 * finishes the very same session AuthController would have built.
 */
class TwoFactorLogin
{
    public const PENDING_KEY = '2fa.pending';

    public const ENROLL_KEY = '2fa.enrollment_required';

    public const MAX_ATTEMPTS = 5;

    public const TTL_SECONDS = 300;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorPolicy $policy,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    /** Call with the password-authenticated user, inside the DB that owns them. */
    public function challengeIfNeeded(Request $request, Authenticatable $user, ?Tenant $tenant): ?JsonResponse
    {
        $session = $request->session();
        $session->forget([self::PENDING_KEY, self::ENROLL_KEY]);

        if (! $this->twoFactor->isEnabled($user)) {
            if ($this->policy->requiredFor($user, $tenant)) {
                $session->put(self::ENROLL_KEY, true);
            }

            return null;
        }

        $remember = $request->boolean('remember');
        Auth::logout();

        $session->put(self::PENDING_KEY, [
            'user_id' => $user->getAuthIdentifier(),
            'tenant_id' => $tenant?->id,
            'remember' => $remember,
            'attempts' => 0,
            'expires_at' => now()->timestamp + self::TTL_SECONDS,
        ]);

        return response()->json(['two_factor_required' => true, 'methods' => ['totp', 'recovery']]);
    }

    public function complete(Request $request, string $input): JsonResponse
    {
        $session = $request->session();
        $pending = $session->get(self::PENDING_KEY);

        if (! $pending || $pending['expires_at'] <= now()->timestamp) {
            $session->forget(self::PENDING_KEY);

            return response()->json(['message' => 'Your sign-in has expired. Enter your password again.', 'code' => 'two_factor_expired'], 401);
        }

        $this->dbm->connectSystem();
        $tenant = $pending['tenant_id'] ? Tenant::find($pending['tenant_id']) : null;
        if ($pending['tenant_id'] && (! $tenant || ! $tenant->isServiceable())) {
            $session->forget(self::PENDING_KEY);

            return response()->json(['message' => 'Sign-in is not available for this account.', 'code' => 'two_factor_expired'], 401);
        }

        [$user, $method] = $tenant
            ? $this->dbm->using($tenant, fn () => $this->check(User::find($pending['user_id']), $input))
            : $this->check(SystemUser::find($pending['user_id']), $input);

        if ($user === null || $method === null) {
            return $this->reject($request, $pending, $user, $tenant);
        }

        $session->forget(self::PENDING_KEY);
        $via = 'password+2fa_'.$method;

        if ($tenant) {
            return app(AuthController::class)->establishTenantSession($request, $user, $tenant, $via);
        }

        Auth::login($user, (bool) $pending['remember']);
        $session->forget(['login.tenant_id', 'impersonate']);
        $session->regenerate();
        app(PlatformAudit::class)->auth($request, 'auth.login', ['email' => $user->email, 'user_id' => $user->id, 'via' => $via]);

        return app(AuthController::class)->me($request);
    }

    /** @return array{0: ?Authenticatable, 1: ?string} */
    private function check(?Authenticatable $user, string $input): array
    {
        return [$user, $user ? $this->twoFactor->verify($user, $input) : null];
    }

    private function reject(Request $request, array $pending, ?Authenticatable $user, ?Tenant $tenant): JsonResponse
    {
        $pending['attempts']++;
        $exhausted = $pending['attempts'] >= self::MAX_ATTEMPTS;

        app(PlatformAudit::class)->auth($request, 'auth.2fa_failed', [
            'email' => $user?->email,
            'user_id' => $user?->getAuthIdentifier(),
            'tenant_id' => $tenant?->id,
            'attempts' => $pending['attempts'],
            'locked' => $exhausted,
        ], null);

        if ($exhausted) {
            $request->session()->forget(self::PENDING_KEY);

            return response()->json(['message' => 'Too many incorrect codes. Enter your password again.', 'code' => 'two_factor_expired'], 401);
        }

        $request->session()->put(self::PENDING_KEY, $pending);

        throw ValidationException::withMessages(['code' => 'That code is not valid.']);
    }
}
