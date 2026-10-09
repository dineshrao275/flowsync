/**
 * The HRMS hub's secondary navigation — the "sub-tabs" of the single HRMS
 * sidebar entry (Phase 3).
 *
 * One sidebar item opens `/hrms`; every feature is a tab inside that hub. The
 * catalog is data on purpose: `HrmsNavTest` reads this file and App.jsx and
 * fails when a tab's capability is not enforced by the matching route gate, or
 * when a route the router owns is missing from this list (an HRMS page nobody
 * can reach from the hub). Without that pin a tab would drift from the policy
 * that guards it and show an entry that leads to a 403 — or, worse, hide a
 * page that still answers on a hand-typed URL.
 *
 * `to` is always the canonical path: every deep link, notification href and
 * `HRMS_MODULE_ROUTES` entry already spells `/hrms/{section}`, so path-based
 * sub-tabs keep bookmarks working with no redirect layer.
 */

export const HRMS_NAV = [
    { to: '/hrms', label: 'Overview', group: 'Overview', capabilities: ['module:hrms.core'] },

    { to: '/hrms/inbox', label: 'Inbox', group: 'Workforce', capabilities: ['module:hrms.core'], badge: 'inbox' },
    { to: '/hrms/team', label: 'My team', group: 'Workforce', capabilities: ['module:hrms.core'] },
    { to: '/hrms/employees', label: 'Employees', group: 'Workforce', capabilities: ['module:hrms.core', 'permission:hrms.employees.view'] },
    { to: '/hrms/org', label: 'Organisation', group: 'Workforce', capabilities: ['module:hrms.core', 'permission:hrms.org.view'] },
    { to: '/hrms/documents', label: 'Documents', group: 'Workforce', capabilities: ['module:hrms.core', 'permission:hrms.documents.view'] },
    { to: '/hrms/documents/mine', label: 'My files', group: 'Workforce', capabilities: ['module:hrms.core'] },
    { to: '/hrms/onboarding', label: 'Onboarding', group: 'Workforce', capabilities: ['module:hrms.core', 'permission:hrms.onboarding.view'] },
    { to: '/hrms/offboarding', label: 'Offboarding', group: 'Workforce', capabilities: ['module:hrms.core', 'permission:hrms.offboarding.view'] },

    { to: '/hrms/attendance', label: 'Attendance', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.attendance'] },
    { to: '/hrms/attendance/approvals', label: 'Approvals', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.attendance', 'permission:hrms.attendance.regularize'] },
    { to: '/hrms/leave', label: 'Leave', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.leave', 'permission:hrms.leave.manage'] },
    { to: '/hrms/leave/mine', label: 'My leave', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.leave'] },
    { to: '/hrms/comp-off', label: 'Comp-off', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.comp_off', 'permission:hrms.comp_off.manage'] },
    { to: '/hrms/comp-off/mine', label: 'My comp-off', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.comp_off'] },
    { to: '/hrms/holidays', label: 'Holidays', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.holidays'] },
    { to: '/hrms/shifts', label: 'Shifts', group: 'Time', capabilities: ['module:hrms.core', 'module:hrms.shifts'] },

    { to: '/hrms/compensation', label: 'Compensation', group: 'Pay & benefits', capabilities: ['module:hrms.core', 'permission:hrms.compensation.view'] },
    { to: '/hrms/payroll', label: 'Payroll', group: 'Pay & benefits', capabilities: ['module:hrms.core', 'permission:hrms.payroll.run'] },
    { to: '/hrms/payroll/mine', label: 'My payslips', group: 'Pay & benefits', capabilities: ['module:hrms.core'] },
    { to: '/hrms/statutory', label: 'Statutory', group: 'Pay & benefits', capabilities: ['module:hrms.core', 'module:hrms.payroll.statutory'] },
    { to: '/hrms/expenses', label: 'Expenses', group: 'Pay & benefits', capabilities: ['module:hrms.core', 'module:hrms.expenses', 'permission:hrms.expenses.view'] },
    { to: '/hrms/expenses/mine', label: 'My expenses', group: 'Pay & benefits', capabilities: ['module:hrms.core', 'module:hrms.expenses'] },

    { to: '/hrms/performance', label: 'Performance', group: 'Performance', capabilities: ['module:hrms.core', 'module:hrms.performance', 'permission:hrms.performance.view'] },
    { to: '/hrms/performance/mine', label: 'My performance', group: 'Performance', capabilities: ['module:hrms.core', 'module:hrms.performance'] },
    { to: '/hrms/engagement', label: 'Engagement', group: 'Performance', capabilities: ['module:hrms.core', 'module:hrms.engagement', 'permission:hrms.engagement.view'] },
    { to: '/hrms/engagement/mine', label: 'My surveys', group: 'Performance', capabilities: ['module:hrms.core', 'module:hrms.engagement'] },

    { to: '/hrms/assets', label: 'Assets', group: 'Records', capabilities: ['module:hrms.core', 'module:hrms.assets', 'permission:hrms.assets.view'] },
    { to: '/hrms/assets/mine', label: 'My assets', group: 'Records', capabilities: ['module:hrms.core', 'module:hrms.assets'] },

    { to: '/hrms/analytics', label: 'Analytics', group: 'Insight', capabilities: ['module:hrms.core', 'module:hrms.analytics', 'permission:hrms.analytics.view'] },
    { to: '/hrms/audit', label: 'Audit log', group: 'Insight', capabilities: ['module:hrms.core', 'permission:hrms.audit.view'] },
];

/**
 * The nav in render order, grouped and filtered to what this caller may open.
 *
 * A tab is *hidden*, not disabled, when its gate fails — the route answers 403
 * either way, and a rail full of dead entries reads as broken navigation.
 *
 * @param {(capability: string) => boolean} check
 * @returns {{ group: string, items: object[] }[]}
 */
export function hrmsNavGroups(check) {
    const groups = new Map();

    for (const tab of HRMS_NAV) {
        if (!tab.capabilities.every((capability) => check(capability))) continue;

        if (!groups.has(tab.group)) groups.set(tab.group, []);
        groups.get(tab.group).push(tab);
    }

    return [...groups.entries()].map(([group, items]) => ({ group, items }));
}

/**
 * The tab a pathname belongs to.
 *
 * Detail routes (`/hrms/employees/7`) stay on their section's tab, and the
 * overview only matches itself — a prefix test would light up Overview on
 * every page in the hub.
 *
 * @param {string} pathname
 * @returns {string}
 */
export function activeHrmsTab(pathname) {
    if (pathname === '/hrms') return '/hrms';

    const match = HRMS_NAV.filter(
        (tab) => tab.to !== '/hrms' && (pathname === tab.to || pathname.startsWith(`${tab.to}/`)),
    ).sort((a, b) => b.to.length - a.to.length)[0];

    return match?.to ?? '/hrms';
}
