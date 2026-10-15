<?php

namespace App\Support;

/**
 * Permission slug → product mapping for the TMS/HRMS split.
 *
 * Mirrors `TenantLimits::productOfModule()` (module keys) for the tenant
 * permission catalog (config/permissions.php slugs): `hrms.*` is HRMS, the
 * shared identity/administration prefixes are product-agnostic (null — shown
 * in both shells), and everything else (workspaces, reports, …) is TMS.
 */
final class ProductCatalog
{
    public const PRODUCT_TMS = 'tms';

    public const PRODUCT_HRMS = 'hrms';

    /** Slug prefixes that are product-agnostic: users, roles, settings, billing, … */
    private const SHARED_PREFIXES = [
        'dashboard.',
        'users.',
        'roles.',
        'settings.',
        'billing.',
        'content.',
        'support.',
        'tenant.',
        'webhooks.',
        'api.',
    ];

    /**
     * Which product a permission slug belongs to: 'tms', 'hrms', or null for
     * the shared identity/administration prefixes (usable in both shells).
     */
    public static function ofPermission(string $slug): ?string
    {
        if (str_starts_with($slug, 'hrms.')) {
            return self::PRODUCT_HRMS;
        }

        foreach (self::SHARED_PREFIXES as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return null;
            }
        }

        return self::PRODUCT_TMS;
    }

    /** Whether a slug may be shown/granted to a tenant with these products enabled. */
    public static function isAllowedFor(string $slug, array $enabledProducts): bool
    {
        $product = self::ofPermission($slug);

        return $product === null || in_array($product, $enabledProducts, true);
    }
}
