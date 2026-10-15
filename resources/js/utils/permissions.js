// Domain grouping for the permission catalog (tenant roles).
//
// Permission slugs carry their domain as a dot path (`hrms.leave.view_own`),
// so the Roles screen groups the flat ~100-checkbox list into sections the
// way config/permissions.php already organises the catalog. The parser is
// deliberately structural — a domain is everything before the last segment —
// so it stays correct as scope variants keep arriving.

export function domainOfPermission(slug) {
    const parts = String(slug).split('.');
    parts.pop();

    return parts.join('.') || slug;
}

export function prettyDomain(domain) {
    const overrides = { hrms: 'HRMS' };

    return domain.split('.').map((segment) => (
        overrides[segment] ?? segment.charAt(0).toUpperCase() + segment.slice(1).replace(/_/g, ' ')
    )).join(' · ');
}

export function groupPermissionsByDomain(permissions) {
    const groups = new Map();

    for (const permission of permissions) {
        const domain = domainOfPermission(permission.slug);

        if (!groups.has(domain)) {
            groups.set(domain, []);
        }

        groups.get(domain).push(permission);
    }

    return [...groups.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([domain, items]) => ({
            domain,
            items: items.sort((a, b) => a.name.localeCompare(b.name)),
        }));
}