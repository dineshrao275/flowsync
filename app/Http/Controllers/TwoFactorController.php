<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TwoFactorChallengeRequest;
use App\Http\Requests\TwoFactorConfirmRequest;
use App\Http\Requests\TwoFactorUpdatePlatformPolicyRequest;
use App\Http\Requests\TwoFactorUpdateTenantPolicyRequest;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\PlatformAudit;
use App\Services\Security\TwoFactorLogin;
use App\Services\Security\TwoFactorPolicy;
use App\Services\Security\TwoFactorService;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * TOTP two-factor (P8.1): the sign-in challenge, self-service enrolment for any
 * signed-in account (tenant user or platform admin), and the enforce-per-role
 * policies (tenant admin picks roles; the platform toggles its own admins).
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorPolicy $policy,
    ) {}

    /** Second sign-in step; unauthenticated by design (the password step already passed). */
    public function challenge(TwoFactorChallengeRequest $request, TwoFactorLogin $login): JsonResponse
    {
        $data = $request->validated();

        return $login->complete($request, $data['code']);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    public function setup(Request $request): JsonResponse
    {
        return response()->json($this->twoFactor->beginEnrollment($request->user()));
    }

    public function confirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        $data = $request->validated();
        $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        $request->session()->forget(TwoFactorLogin::ENROLL_KEY);

        $this->audit($request, 'auth.2fa_enabled');

        return response()->json(['message' => __('Two-factor authentication is on.'), 'recovery_codes' => $codes, ...$this->state($request)]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertReauthenticated($request);

        if ($this->policy->requiredFor($user, $this->currentTenant())) {
            throw ValidationException::withMessages(['form' => __('Your organisation requires two-factor authentication for your role.')]);
        }

        $this->twoFactor->disable($user);
        $this->audit($request, 'auth.2fa_disabled');

        return response()->json(['message' => __('Two-factor authentication is off.'), ...$this->state($request)]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $this->assertReauthenticated($request);
        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());
        $this->audit($request, 'auth.2fa_recovery_regenerated');

        return response()->json(['message' => __('New recovery codes generated. The old ones no longer work.'), 'recovery_codes' => $codes, ...$this->state($request)]);
    }

    public function showTenantPolicy(): JsonResponse
    {
        return response()->json($this->tenantPolicyPayload($this->currentTenant()));
    }

    public function updateTenantPolicy(TwoFactorUpdateTenantPolicyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenant = $this->currentTenant() ?? abort(404);
        $before = $this->policy->tenantRoles($tenant);

        $this->policy->setTenantRoles($tenant, $data['roles'], Role::query()->pluck('slug')->all());

        app(PlatformAudit::class)->diff($request, 'tenant.two_factor_policy_updated', 'tenants', $tenant->id,
            ['roles' => $before], ['roles' => $this->policy->tenantRoles($tenant->refresh())]);

        return response()->json(['message' => __('Policy saved.'), ...$this->tenantPolicyPayload($tenant)]);
    }

    public function showPlatformPolicy(): JsonResponse
    {
        return response()->json(['require_super_admins' => PlatformSetting::bool(TwoFactorPolicy::PLATFORM_KEY, false)]);
    }

    public function updatePlatformPolicy(TwoFactorUpdatePlatformPolicyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $before = PlatformSetting::bool(TwoFactorPolicy::PLATFORM_KEY, false);

        PlatformSetting::set(TwoFactorPolicy::PLATFORM_KEY, (bool) $data['require_super_admins']);

        app(PlatformAudit::class)->diff($request, 'platform.two_factor_policy_updated', 'platform_settings', null,
            ['require_super_admins' => $before], ['require_super_admins' => (bool) $data['require_super_admins']]);

        return response()->json(['message' => __('Policy saved.'), 'require_super_admins' => (bool) $data['require_super_admins']]);
    }

    /** Sensitive 2FA changes need the password AND a current code, so a hijacked session cannot strip the factor. */
    private function assertReauthenticated(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => __('Incorrect password.')]);
        }
        if ($this->twoFactor->verify($user, $data['code']) === null) {
            $this->audit($request, 'auth.2fa_failed');
            throw ValidationException::withMessages(['code' => __('That code is not valid.')]);
        }
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        $user = $request->user();

        return [
            'enabled' => $this->twoFactor->isEnabled($user),
            'recovery_codes_remaining' => $this->twoFactor->recoveryCodesRemaining($user),
            'required' => $this->policy->requiredFor($user, $this->currentTenant()),
        ];
    }

    /** @return array<string, mixed> */
    private function tenantPolicyPayload(?Tenant $tenant): array
    {
        return [
            'roles' => $this->policy->tenantRoles($tenant),
            'available_roles' => Role::query()->orderBy('name')->get(['slug', 'name']),
        ];
    }

    private function currentTenant(): ?Tenant
    {
        $id = app(TenantContext::class)->currentId();

        return $id ? Tenant::find($id) : null;
    }

    private function audit(Request $request, string $action): void
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        app(PlatformAudit::class)->auth($request, $action, [
            'email' => $user->email,
            'user_id' => $user->getAuthIdentifier(),
            'tenant_id' => app(TenantContext::class)->currentId(),
        ]);
    }
}
