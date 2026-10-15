import {
    Archive,
    Buildings,
    ChartBar,
    ChartLine,
    Clock,
    CreditCard,
    DownloadSimple,
    Envelope,
    FileText,
    Flag,
    Folders,
    Gear,
    Gift,
    Globe,
    GridFour,
    Image,
    Kanban,
    Lifebuoy,
    Lightning,
    LockKey,
    MagnifyingGlass,
    Newspaper,
    ShieldCheck,
    SlidersHorizontal,
    SquaresFour,
    UserCircle,
    Users,
} from '@phosphor-icons/react';
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
    dashboard: SquaresFour,
    myHr: UserCircle,
    workspaces: Folders,
    projects: Kanban,
    search: MagnifyingGlass,
    reports: ChartBar,
    users: Users,
    roles: ShieldCheck,
    webhooks: Lightning,
    settings: Gear,
    security: LockKey,
    subscription: CreditCard,
    support: Lifebuoy,
    dataExport: DownloadSimple,
    clock: Clock,
    grid: GridFour,
    chart: ChartLine,
    archive: Archive,
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
            { to: '/tenants', label: 'Tenants', capabilities: ['tenants.view'], icon: Buildings },
            { to: '/plans', label: 'Plans', capabilities: ['billing.view'], icon: FileText },
            { to: '/admin/features', label: 'Features', capabilities: ['billing.view'], icon: Gift },
            { to: '/admin/feature-flags', label: 'Feature flags', capabilities: ['flags.view'], icon: Flag },
            { to: '/admin/pages', label: 'Website', capabilities: ['content.view'], icon: Globe },
            { to: '/admin/media', label: 'Media', capabilities: ['content.view'], icon: Image },
            { to: '/admin/site-settings', label: 'Site settings', capabilities: ['content.view'], icon: SlidersHorizontal },
            { to: '/admin/blog', label: 'Blog', capabilities: ['content.view'], icon: Newspaper },
        ],
    },
    {
        label: 'Platform',
        items: [
            { to: '/admin/users', label: 'Users', capabilities: ['platform_users.view'], icon: ICONS.users },
            { to: '/admin/support', label: 'Support', capabilities: ['support.view'], icon: ICONS.support },
            { to: '/admin/leads', label: 'Leads', capabilities: ['leads.view'], icon: Envelope },
            { to: '/admin/audit-logs', label: 'Audit Logs', capabilities: ['audit.view'], icon: ICONS.archive },
            { to: '/admin/settings', label: 'Settings', capabilities: ['settings.view'], icon: ICONS.settings },
            { to: '/security', label: 'Security', icon: ICONS.security },
        ],
    },
];
