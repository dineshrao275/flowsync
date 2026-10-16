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
    'nav.groups.overview': ICONS.grid,
    'nav.groups.workforce': ICONS.users,
    'nav.groups.time': ICONS.clock,
    'nav.groups.pay': ICONS.subscription,
    'nav.groups.performance': ICONS.chart,
    'nav.groups.records': ICONS.archive,
    'nav.groups.insight': ICONS.search,
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
    sections.find((section) => section.label === 'nav.groups.overview')?.items.unshift({
        to: '/my',
        label: 'nav.hrms.myHr',
        capabilities: ['module:hrms.core'],
        icon: ICONS.myHr,
    });

    return sections;
}

const SHARED_ADMIN = [
    { to: '/users', label: 'nav.admin.users', capabilities: ['users.view'], icon: ICONS.users },
    { to: '/roles', label: 'nav.admin.roles', capabilities: ['roles.view'], icon: ICONS.roles },
    { to: '/settings', label: 'nav.admin.settings', capabilities: ['settings.view'], icon: ICONS.settings },
    { to: '/security', label: 'nav.admin.security', icon: ICONS.security },
];

const SHARED_BILLING = [
    { to: '/subscription', label: 'nav.admin.subscription', capabilities: ['billing.view'], icon: ICONS.subscription },
    { to: '/support', label: 'nav.admin.support', capabilities: ['support.manage'], icon: ICONS.support },
    { to: '/export', label: 'nav.admin.dataExport', capabilities: ['module:export.full', 'billing.view'], icon: ICONS.dataExport },
];

export const tmsSections = [
    {
        label: 'nav.groups.main',
        items: [
            { to: '/dashboard', label: 'nav.tms.dashboard', capabilities: ['dashboard.view', 'product:tms'], icon: ICONS.dashboard },
            { to: '/workspaces', label: 'nav.tms.workspaces', capabilities: ['workspaces.view', 'product:tms'], icon: ICONS.workspaces },
            { to: '/projects', label: 'nav.tms.projects', capabilities: ['workspaces.view', 'product:tms'], icon: ICONS.projects },
            { to: '/timesheets', label: 'nav.tms.timesheets', capabilities: ['workspaces.view', 'product:tms', 'module:timesheets'], icon: ICONS.clock },
            { to: '/search', label: 'nav.tms.search', capabilities: ['workspaces.view', 'product:tms', 'module:global_search'], icon: ICONS.search },
        ],
    },
    {
        label: 'nav.groups.insights',
        items: [
            { to: '/reports', label: 'nav.tms.reports', capabilities: ['reports.view', 'product:tms', 'module:reports'], icon: ICONS.reports },
        ],
    },
    {
        label: 'nav.groups.administration',
        items: [
            ...SHARED_ADMIN.slice(0, 2),
            { to: '/webhooks', label: 'nav.tms.webhooks', capabilities: ['webhooks.manage', 'module:webhooks'], icon: ICONS.webhooks },
            ...SHARED_ADMIN.slice(2),
        ],
    },
    {
        label: 'nav.groups.billing',
        items: SHARED_BILLING,
    },
];

export const hrmsSections = [
    ...hrmsGroups(),
    {
        label: 'nav.groups.administration',
        items: SHARED_ADMIN,
    },
    {
        label: 'nav.groups.billing',
        items: SHARED_BILLING,
    },
];

export const superAdminSections = [
    {
        label: 'nav.groups.administration',
        items: [
            { to: '/admin', label: 'nav.platform.overview', capabilities: ['tenants.view'], icon: ICONS.grid },
            { to: '/tenants', label: 'nav.platform.tenants', capabilities: ['tenants.view'], icon: Buildings },
            { to: '/plans', label: 'nav.platform.plans', capabilities: ['billing.view'], icon: FileText },
            { to: '/admin/features', label: 'nav.platform.features', capabilities: ['billing.view'], icon: Gift },
            { to: '/admin/feature-flags', label: 'nav.platform.featureFlags', capabilities: ['flags.view'], icon: Flag },
            { to: '/admin/pages', label: 'nav.platform.website', capabilities: ['content.view'], icon: Globe },
            { to: '/admin/media', label: 'nav.platform.media', capabilities: ['content.view'], icon: Image },
            { to: '/admin/site-settings', label: 'nav.platform.siteSettings', capabilities: ['content.view'], icon: SlidersHorizontal },
            { to: '/admin/blog', label: 'nav.platform.blog', capabilities: ['content.view'], icon: Newspaper },
        ],
    },
    {
        label: 'nav.groups.platform',
        items: [
            { to: '/admin/users', label: 'nav.admin.users', capabilities: ['platform_users.view'], icon: ICONS.users },
            { to: '/admin/support', label: 'nav.admin.support', capabilities: ['support.view'], icon: ICONS.support },
            { to: '/admin/leads', label: 'nav.platform.leads', capabilities: ['leads.view'], icon: Envelope },
            { to: '/admin/audit-logs', label: 'nav.platform.auditLogs', capabilities: ['audit.view'], icon: ICONS.archive },
            { to: '/admin/settings', label: 'nav.admin.settings', capabilities: ['settings.view'], icon: ICONS.settings },
            { to: '/security', label: 'nav.admin.security', icon: ICONS.security },
        ],
    },
];
