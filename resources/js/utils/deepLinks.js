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
 * An employee profile, optionally at one of its tabs.
 *
 * The tab lives in the URL, not in component state, for the same reason the
 * project and workspace pages keep theirs there: a link from a notification or
 * the directory has to be able to land on a specific section and survive a
 * refresh.
 */
export function employeeUrl(employeeId, tab = null) {
    if (!tab) return `/hrms/employees/${employeeId}`;
    return `/hrms/employees/${employeeId}?tab=${tab}`;
}

/**
 * The org page, optionally with a department selected.
 *
 * The selection is a query parameter because the org chart is the one HRMS
 * screen a person is sent a link to: "the headcount badge on this team is wrong"
 * is a message about a specific node, and a link that lands on the page with
 * nothing selected has lost the point of the message.
 */
export function orgUrl(departmentId = null) {
    return departmentId ? `/hrms/org?department=${encodeURIComponent(departmentId)}` : '/hrms/org';
}

/**
 * One payroll run's review grid. The run id is tenant-local like everything
 * else here, so callers resolve it from their own responses, never from a
 * cross-tenant payload.
 */
export function payrollRunUrl(runId) {
    return `/hrms/payroll/runs/${runId}`;
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
