export function taskUrl(projectId, key, section = null) {
    let url = `/projects/${projectId}?tab=tasks&task=${encodeURIComponent(key)}`;
    if (section) url += `&section=${section}`;
    return url;
}

export function projectUrl(projectId, tab = null) {
    if (!tab) return `/projects/${projectId}`;
    return `/projects/${projectId}?tab=${tab}`;
}

export function workspaceUrl(workspaceId, tab = null) {
    if (!tab) return `/workspaces/${workspaceId}`;
    return `/workspaces/${workspaceId}?tab=${tab}`;
}

/**
 * Where a person belongs after signing in. A platform super admin has no tenant
 * context (the tenant dashboard endpoints 403 for them), so it lands on the
 * platform overview instead.
 */
export function homeRouteFor(user) {
    return user?.is_super_admin && !user?.impersonating ? '/admin' : '/dashboard';
}

/**
 * Deep link into an HRMS section, e.g. `hrmsUrl('attendance', { date: '2026-09-27' })`.
 *
 * Every section builds on the same `/hrms/:section` base, so a notification can
 * point at a payroll run or an attendance day without knowing which phase
 * shipped the page.
 */
export function hrmsUrl(section, params = null) {
    const base = section ? `/hrms/${section}` : '/hrms';
    if (!params) return base;

    const query = new URLSearchParams(params).toString();

    return query ? `${base}?${query}` : base;
}
