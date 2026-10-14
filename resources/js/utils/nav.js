import { HRMS_NAV } from './hrmsNav';

/**
 * The two product shells' sidebars, plus the platform sidebar.
 *
 * `tmsSections` is the task-management shell (Main/Insights/Administration/
 * Billing). `hrmsSections` is the HR shell: the HRMS hub tabs from
 * `hrmsNav.js` — derived, never re-listed, so a hub tab cannot drift from the
 * sidebar that offers it — followed by the shared Administration/Billing
 * sections an HRMS-only tenant administers (Users, Roles, Settings,
 * Security, Subscription, Support). Webhooks stays TMS-shell-only: it is a
 * task-event surface, and the HRMS brief lists Users/Roles/Settings only.
 *
 * Every item is still capability-gated by the Sidebar filter, exactly like
 * the route gates in App.jsx — a section a caller cannot use filters to zero
 * and drops out (or shows the locked-by-plan note).
 */

const ICONS = {
    dashboard: 'M3 12l9-9 9 9M5 10v10h14V10',
    myHr: 'M12 12a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 0114 0',
    workspaces: 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 012 2H5a2 2 0 01-2-2V7z',
    projects: 'M2 4h20v16H2V4zm2 2v2h16V6H4zm0 6h16v-2H4v2zm0 4h16v-2H4v2z',
    search: 'M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z',
    reports: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    users: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m4-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 2a3 3 0 10-3-3',
    roles: 'M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4zm-1 10l-2-2-1 1 3 3 5-5-1-1-4 4z',
    webhooks: 'M13 10V3L4 14h7v7l9-11h-7z',
    settings: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 001.065-2.572c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    security: 'M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z',
    subscription: 'M3 17l5-2 8 3 5-2v6H3v-5zm5-4l5-2 8 3 3-1v-3l-3-1-8-3-5 2-5-2 3 2v3l2 1z',
    support: 'M8 10h8M8 14h5m-9 7l3-3h11a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v15z',
    dataExport: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
    clock: 'M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z',
    grid: 'M4 13h6V3H4v10zm0 8h6v-6H4v6zm10 0h6V11h-6v10zm0-18v6h6V3h-6z',
    chart: 'M3 3v18h18M8 17V9m4 8V5m4 12v-6',
    archive: 'M4 7V5a2 2 0 012-2h8l2 2h6v14a2 2 0 01-2 2H6a2 2 0 01-2-2v-2m0 0V7h18M8 12h8M8 16h8',
};

// One icon per HRMS hub group: the rail lists ~30 tabs, and per-tab artwork
// would be a second icon catalog to keep in step with hrmsNav.js.
const GROUP_ICONS = {
    Overview: ICONS.grid,
    Workforce: ICONS.users,
    Time: ICONS.clock,
    'Pay & benefits': ICONS.subscription,
    Performance: ICONS.chart,
    Records: ICONS.archive,
    Insight: ICONS.search,
};

/** HRMS hub tabs as collapsible sidebar sections, in HRMS_NAV order. */
function hrmsGroups() {
    const groups = new Map();

    for (const tab of HRMS_NAV) {
        if (!groups.has(tab.group)) groups.set(tab.group, []);
        groups.get(tab.group).push({
            to: tab.to,
            label: tab.label,
            capabilities: [...tab.capabilities],
            icon: GROUP_ICONS[tab.group] ?? ICONS.clock,
            ...(tab.badge ? { badge: tab.badge } : {}),
        });
    }

    const sections = [...groups.entries()].map(([label, items]) => ({ label, items, collapsible: true }));

    // My HR is self-service HR, not a hub tab — but it belongs to this shell,
    // not the task shell, so it leads the Overview group.
    sections.find((section) => section.label === 'Overview')?.items.unshift({
        to: '/my',
        label: 'My HR',
        capabilities: ['module:hrms.core'],
        icon: ICONS.myHr,
    });

    return sections;
}

const SHARED_ADMIN = [
    { to: '/users', label: 'Users', capabilities: ['users.view'], icon: ICONS.users },
    { to: '/roles', label: 'Roles', capabilities: ['roles.view'], icon: ICONS.roles },
    { to: '/settings', label: 'Settings', capabilities: ['settings.view'], icon: ICONS.settings },
    { to: '/security', label: 'Security', icon: ICONS.security },
];

const SHARED_BILLING = [
    { to: '/subscription', label: 'Subscription', capabilities: ['billing.view'], icon: ICONS.subscription },
    { to: '/support', label: 'Support', capabilities: ['support.manage'], icon: ICONS.support },
    { to: '/export', label: 'Data Export', capabilities: ['module:export.full', 'billing.view'], icon: ICONS.dataExport },
];

export const tmsSections = [
    {
        label: 'Main',
        items: [
            { to: '/dashboard', label: 'Dashboard', capabilities: ['dashboard.view', 'product:tms'], icon: ICONS.dashboard },
            { to: '/workspaces', label: 'Workspaces', capabilities: ['workspaces.view', 'product:tms'], icon: ICONS.workspaces },
            { to: '/projects', label: 'Projects', capabilities: ['workspaces.view', 'product:tms'], icon: ICONS.projects },
            { to: '/search', label: 'Search', capabilities: ['workspaces.view', 'product:tms', 'module:global_search'], icon: ICONS.search },
        ],
    },
    {
        label: 'Insights',
        items: [
            { to: '/reports', label: 'Reports', capabilities: ['reports.view', 'product:tms', 'module:reports'], icon: ICONS.reports },
        ],
    },
    {
        label: 'Administration',
        items: [
            ...SHARED_ADMIN.slice(0, 2),
            { to: '/webhooks', label: 'Webhooks', capabilities: ['webhooks.manage', 'module:webhooks'], icon: ICONS.webhooks },
            ...SHARED_ADMIN.slice(2),
        ],
    },
    {
        label: 'Billing',
        items: SHARED_BILLING,
    },
];

export const hrmsSections = [
    ...hrmsGroups(),
    {
        label: 'Administration',
        items: SHARED_ADMIN,
    },
    {
        label: 'Billing',
        items: SHARED_BILLING,
    },
];

export const superAdminSections = [
    {
        label: 'Administration',
        items: [
            { to: '/admin', label: 'Overview', capabilities: ['tenants.view'], icon: ICONS.grid },
            { to: '/tenants', label: 'Tenants', capabilities: ['tenants.view'], icon: 'M3 20h18M6 8V6a3 3 0 013-3h6a3 3 0 013 3v2m-12 0h12a3 3 0 013 3v5a3 3 0 01-3 3H9a3 3 0 01-3-3v-5a3 3 0 013-3z' },
            { to: '/plans', label: 'Plans', capabilities: ['billing.view'], icon: 'M9 3h6l4 4v14H5V7l4-4zm0 5h6m-4 4h4m-4 4h4' },
            { to: '/admin/features', label: 'Features', capabilities: ['billing.view'], icon: 'M12 3l8 4v13H4V7l8-4zm0 5a2.5 2.5 0 00-2.5 2.5c0 1.5 2.5 3 2.5 3s2.5-1.5 2.5-3A2.5 2.5 0 0012 8zm0 10a2 2 0 100-4 2 2 0 000 4z' },
            { to: '/admin/feature-flags', label: 'Feature flags', capabilities: ['flags.view'], icon: 'M5 3v18M5 4h12l-2 4 2 4H5' },
            { to: '/admin/pages', label: 'Website', capabilities: ['content.view'], icon: 'M12 3l8 4v13H4V7l8-4zm2 6v6m-4-6v2' },
        ],
    },
    {
        label: 'Platform',
        items: [
            { to: '/admin/users', label: 'Users', capabilities: ['platform_users.view'], icon: ICONS.users },
            { to: '/admin/support', label: 'Support', capabilities: ['support.view'], icon: ICONS.support },
            { to: '/admin/audit-logs', label: 'Audit Logs', capabilities: ['audit.view'], icon: ICONS.archive },
            { to: '/admin/settings', label: 'Settings', capabilities: ['settings.view'], icon: ICONS.settings },
            { to: '/security', label: 'Security', icon: ICONS.security },
        ],
    },
];
