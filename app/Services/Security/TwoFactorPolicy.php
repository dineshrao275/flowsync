<?php

namespace App\Services\Security;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Who MUST use two-factor (P8.1 enforce-per-role).
 *  - Tenant users: the tenant's chosen role slugs, stored in the central
 *    `tenants.settings.security.two_factor_roles` (a tenant admin edits it).
 *  - Platform accounts: the `require_2fa_super_admin` platform setting.
 * Enforcement is at sign-in: a required user without a credential gets a
 * session limited to enrolment until they finish (EnsureTwoFactorEnrolled).
 */
class TwoFactorPolicy
{
    public const PLATFORM_KEY = 'require_2fa_super_admin';

    public function requiredFor(Authenticatable $user, ?Tenant $tenant): bool
    {
        if ($user->is_super_admin ?? false) {
            return PlatformSetting::bool(self::PLATFORM_KEY, false);
        }

        $roles = $this->tenantRoles($tenant);

        return $roles !== [] && $user instanceof User && array_intersect($roles, $user->roleSlugs()) !== [];
    }

    /** @return list<string> */
    public function tenantRoles(?Tenant $tenant): array
    {
        return array_values((array) ($tenant?->settings['security']['two_factor_roles'] ?? []));
    }

    /**
     * @param  list<string>  $slugs  role slugs that must use 2FA ([] = nobody)
     */
    public function setTenantRoles(Tenant $tenant, array $slugs, array $knownSlugs): void
    {
        $unknown = array_diff($slugs, $knownSlugs);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['roles' => 'Unknown role: '.implode(', ', $unknown)]);
        }

        $settings = (array) $tenant->settings;
        $settings['security']['two_factor_roles'] = array_values(array_unique($slugs));
        $tenant->update(['settings' => $settings]);
    }
}
