/**
 * The HRMS hub's navigation catalog (Phase 3).
 *
 * The HR product shell's sidebar (`utils/nav.js` `hrmsSections`) is derived
 * from this list — never re-listed — so a sidebar entry cannot drift from
 * the tab capabilities it filters by. The catalog is data on purpose:
 * `HrmsNavTest` reads this file and App.jsx and fails when a tab's capability
 * is not enforced by the matching route gate, or when a route the router owns
 * is missing from this list (an HRMS page nobody can reach from the shell).
 * Without that pin a tab would drift from the policy that guards it and show
 * an entry that leads to a 403 — or, worse, hide a page that still answers on
 * a hand-typed URL.
 *
 * `to` is always the canonical path: every deep link, notification href and
 * `HRMS_MODULE_ROUTES` entry already spells `/hrms/{section}`, so path-based
 * tabs keep bookmarks working with no redirect layer.
 */

// `label` and `group` are i18n keys (translated by the Sidebar), not literals:
// the catalog stays data the shell test can parse, and the strings live in
// `common.json` `nav.*`. Keep each entry on one line, `to → label → group →
// capabilities`, or `HrmsNavTest::navTabs()` stops matching it.
export const HRMS_NAV = [
    { to: '/hrms', label: 'nav.hrms.overview', group: 'nav.groups.overview', capabilities: ['module:hrms.core'] },

    { to: '/hrms/inbox', label: 'nav.hrms.inbox', group: 'nav.groups.workforce', capabilities: ['module:hrms.core'], badge: 'inbox' },
    { to: '/hrms/team', label: 'nav.hrms.myTeam', group: 'nav.groups.workforce', capabilities: ['module:hrms.core'] },
    { to: '/hrms/employees', label: 'nav.hrms.employees', group: 'nav.groups.workforce', capabilities: ['module:hrms.core', 'permission:hrms.employees.view'] },
    { to: '/hrms/org', label: 'nav.hrms.org', group: 'nav.groups.workforce', capabilities: ['module:hrms.core', 'permission:hrms.org.view'] },
    { to: '/hrms/documents', label: 'nav.hrms.documents', group: 'nav.groups.workforce', capabilities: ['module:hrms.core', 'permission:hrms.documents.view'] },
    { to: '/hrms/documents/mine', label: 'nav.hrms.myFiles', group: 'nav.groups.workforce', capabilities: ['module:hrms.core'] },
    { to: '/hrms/onboarding', label: 'nav.hrms.onboarding', group: 'nav.groups.workforce', capabilities: ['module:hrms.core', 'permission:hrms.onboarding.view'] },
    { to: '/hrms/offboarding', label: 'nav.hrms.offboarding', group: 'nav.groups.workforce', capabilities: ['module:hrms.core', 'permission:hrms.offboarding.view'] },

    { to: '/hrms/attendance', label: 'nav.hrms.attendance', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.attendance'] },
    { to: '/hrms/attendance/approvals', label: 'nav.hrms.approvals', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.attendance', 'permission:hrms.attendance.regularize'] },
    { to: '/hrms/leave', label: 'nav.hrms.leave', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.leave', 'permission:hrms.leave.manage'] },
    { to: '/hrms/leave/mine', label: 'nav.hrms.myLeave', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.leave'] },
    { to: '/hrms/comp-off', label: 'nav.hrms.compOff', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.comp_off', 'permission:hrms.comp_off.manage'] },
    { to: '/hrms/comp-off/mine', label: 'nav.hrms.myCompOff', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.comp_off'] },
    { to: '/hrms/holidays', label: 'nav.hrms.holidays', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.holidays'] },
    { to: '/hrms/shift-swaps', label: 'nav.hrms.shiftSwaps', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.shifts'] },
    { to: '/hrms/shifts', label: 'nav.hrms.shifts', group: 'nav.groups.time', capabilities: ['module:hrms.core', 'module:hrms.shifts'] },

    { to: '/hrms/compensation', label: 'nav.hrms.compensation', group: 'nav.groups.pay', capabilities: ['module:hrms.core', 'module:hrms.compensation', 'permission:hrms.compensation.view'] },
    { to: '/hrms/payroll', label: 'nav.hrms.payroll', group: 'nav.groups.pay', capabilities: ['module:hrms.core', 'module:hrms.payroll', 'permission:hrms.payroll.run'] },
    { to: '/hrms/payroll/mine', label: 'nav.hrms.myPayslips', group: 'nav.groups.pay', capabilities: ['module:hrms.core', 'module:hrms.payroll'] },
    { to: '/hrms/statutory', label: 'nav.hrms.statutory', group: 'nav.groups.pay', capabilities: ['module:hrms.core', 'module:hrms.payroll.statutory'] },
    { to: '/hrms/expenses', label: 'nav.hrms.expenses', group: 'nav.groups.pay', capabilities: ['module:hrms.core', 'module:hrms.expenses', 'permission:hrms.expenses.view'] },
    { to: '/hrms/expenses/mine', label: 'nav.hrms.myExpenses', group: 'nav.groups.pay', capabilities: ['module:hrms.core', 'module:hrms.expenses'] },

    { to: '/hrms/performance', label: 'nav.hrms.performance', group: 'nav.groups.performance', capabilities: ['module:hrms.core', 'module:hrms.performance', 'permission:hrms.performance.view'] },
    { to: '/hrms/performance/mine', label: 'nav.hrms.myPerformance', group: 'nav.groups.performance', capabilities: ['module:hrms.core', 'module:hrms.performance'] },
    { to: '/hrms/engagement', label: 'nav.hrms.engagement', group: 'nav.groups.performance', capabilities: ['module:hrms.core', 'module:hrms.engagement', 'permission:hrms.engagement.view'] },
    { to: '/hrms/engagement/mine', label: 'nav.hrms.mySurveys', group: 'nav.groups.performance', capabilities: ['module:hrms.core', 'module:hrms.engagement'] },
    { to: '/hrms/skills', label: 'nav.hrms.skills', group: 'nav.groups.performance', capabilities: ['module:hrms.core', 'module:hrms.talent'] },
    { to: '/hrms/talent', label: 'nav.hrms.talent', group: 'nav.groups.performance', capabilities: ['module:hrms.core', 'module:hrms.talent', 'permission:hrms.talent.manage'] },

    { to: '/hrms/assets', label: 'nav.hrms.assets', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:hrms.assets', 'permission:hrms.assets.view'] },
    { to: '/hrms/resignation', label: 'nav.hrms.resignation', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:hrms.offboarding'] },
    { to: '/hrms/exit-interview', label: 'nav.hrms.exitInterview', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:hrms.offboarding'] },
    { to: '/hrms/automation', label: 'nav.hrms.hrAutomation', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:automation', 'permission:hrms.onboarding.manage'] },
    { to: '/hrms/improvement-plans', label: 'nav.hrms.improvementPlans', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:hrms.performance'] },
    { to: '/hrms/loans', label: 'nav.hrms.loans', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:hrms.payroll'] },
    { to: '/hrms/letters', label: 'nav.hrms.letters', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:hrms.documents', 'permission:hrms.documents.manage'] },
    { to: '/hrms/changes', label: 'nav.hrms.changes', group: 'nav.groups.records', capabilities: ['module:hrms.core'] },
    { to: '/hrms/assets/mine', label: 'nav.hrms.myAssets', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'module:hrms.assets'] },
    { to: '/hrms/approvals/chains', label: 'nav.hrms.approvalChains', group: 'nav.groups.records', capabilities: ['module:hrms.core', 'permission:hrms.approvals.manage'] },

    { to: '/hrms/analytics', label: 'nav.hrms.analytics', group: 'nav.groups.insight', capabilities: ['module:hrms.core', 'module:hrms.analytics', 'permission:hrms.analytics.view'] },
    { to: '/hrms/audit', label: 'nav.hrms.audit', group: 'nav.groups.insight', capabilities: ['module:hrms.core', 'permission:hrms.audit.view'] },
];

