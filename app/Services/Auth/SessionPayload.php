<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\FeatureFlags;
use App\Services\Security\PlatformAccess;
use App\Services\Security\TwoFactorLogin;
use App\Services\Security\TwoFactorService;
use App\Services\TenantLimits;
use App\Services\TenantOnboarding;
use App\Support\ThemeMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The signed-in session payload (`/auth/me` and every login response): the user, roles and
 * permissions, tenant, products, modules, theme and impersonation state. Extracted from
 * AuthController unchanged.
 */
class SessionPayload
{
    public function build(Request $request): array
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
                'timezone' => (! $isSuperAdmin ? ($user->settings?->settings['timezone'] ?? null) : null) ?? $tenant?->timezone ?? config('app.timezone', 'UTC'),
                'locale' => (! $isSuperAdmin ? ($user->settings?->settings['locale'] ?? null) : null) ?? $tenant?->locale ?? config('app.locale', 'en'),
                'is_super_admin' => $isSuperAdmin,
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'timezone' => $tenant->timezone,
                    'locale' => $tenant->locale,
                    'branding' => [
                        'primary_color' => $tenant->brand_primary_color,
                        'logo_url' => $tenant->brand_logo_url,
                    ],
                ] : null,
                'roles' => $isSuperAdmin ? [] : $user->roleSlugs(),
                'permissions' => $isSuperAdmin ? [] : $user->permissionSlugs(),
                'modules' => $tenant
                    ? app(TenantLimits::class)->visibleModules($tenant)
                    : config('subscriptions.modules'),
                // Whole products the tenant has (a Super Admin sees both): drives the sidebar and the landing page.
                'products' => [
                    'tms' => ! $tenant || app(TenantLimits::class)->productEnabled($tenant, 'tms'),
                    'hrms' => ! $tenant || app(TenantLimits::class)->productEnabled($tenant, 'hrms'),
                ],
                'impersonating' => $impersonation !== null,
                'impersonated_by' => $impersonation['original_user_id'] ?? null,
                'impersonation_mode' => $impersonation['mode'] ?? null,
                'impersonation_expires_at' => isset($impersonation['expires_at']) ? Carbon::createFromTimestamp($impersonation['expires_at'])->toIso8601String() : null,
                'onboarding_complete' => ! $tenant || app(TenantOnboarding::class)->isComplete($tenant),
                // Trial/subscription ended with no paid plan: the SPA confines the session to the Subscription page.
                'subscription_required' => $tenant !== null && ($tenant->status === Tenant::STATUS_EXPIRED || $tenant->needsPaymentMethod()),
                'payment_method_required' => $tenant !== null && $tenant->needsPaymentMethod(),
                // Runtime feature flags resolved for this tenant (P8.7); the SPA only mirrors them.
                // Platform personas (P8.3): null = unrestricted break-glass admin; otherwise the held slugs.
                'platform' => $isSuperAdmin ? [
                    'roles' => app(PlatformAccess::class)->roleSlugsOf($user),
                    'permissions' => app(PlatformAccess::class)->permissionsOf($user),
                ] : null,
                'two_factor' => [
                    'enabled' => app(TwoFactorService::class)->isEnabled($user),
                    'enrollment_required' => (bool) $request->session()->get(TwoFactorLogin::ENROLL_KEY, false),
                ],
                'flags' => $tenant ? app(FeatureFlags::class)->allFor($tenant->id) : (object) [],
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
