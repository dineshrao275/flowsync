/**
 * Comprehensive feature and module catalog for subscription plans and capabilities.
 * Covers all 35 modules in FlowSync across TMS (Task Management), HRMS (Human Resources),
 * and Platform/Add-ons.
 */

export const MODULE_CATALOG = {
    // TMS · Task & Project Management
    time_tracking: {
        key: 'time_tracking',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'time_tracking',
        defaultLabel: 'Time Tracking',
        defaultDescription: 'Track billable and non-billable time, log work hours directly on tasks, and analyze team timespent.',
    },
    sprints: {
        key: 'sprints',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'sprints',
        defaultLabel: 'Sprints & Agile',
        defaultDescription: 'Plan agile sprints, manage backlogs, track sprint burndown charts, and run retrospectives.',
    },
    automation: {
        key: 'automation',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'automation',
        defaultLabel: 'Workflow Automation',
        defaultDescription: 'Build no-code automation rules to auto-assign tasks, transition statuses, and trigger notifications.',
    },
    timesheets: {
        key: 'timesheets',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'timesheets',
        defaultLabel: 'Weekly Timesheets',
        defaultDescription: 'Submit structured weekly employee timesheets with multi-level manager approvals.',
    },
    project_templates: {
        key: 'project_templates',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'project_templates',
        defaultLabel: 'Project Templates',
        defaultDescription: 'Standardize project kickoffs with pre-configured workflows, task lists, and components.',
    },
    jql: {
        key: 'jql',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'jql',
        defaultLabel: 'Advanced Search (JQL)',
        defaultDescription: 'Filter and query tasks using powerful Jira-style query syntax and boolean parameters.',
    },
    saved_filters: {
        key: 'saved_filters',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'saved_filters',
        defaultLabel: 'Saved & Shared Filters',
        defaultDescription: 'Save custom task views, share filtered dashboards with your team, and bookmark queries.',
    },
    custom_fields: {
        key: 'custom_fields',
        product: 'tms',
        category: 'tms',
        group: 'Task Management',
        i18nKey: 'custom_fields',
        defaultLabel: 'Custom Fields',
        defaultDescription: 'Add custom text, dropdown, date, and numeric attributes to tasks, projects, and entities.',
    },

    // HRMS · Core & Workforce
    'hrms.core': {
        key: 'hrms.core',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Core',
        i18nKey: 'hrmsCore',
        defaultLabel: 'Employee Directory',
        defaultDescription: 'Centralized directory of employee profiles, organizational structure, designations, and contacts.',
    },
    'hrms.onboarding': {
        key: 'hrms.onboarding',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Core',
        i18nKey: 'hrmsOnboarding',
        defaultLabel: 'New Hire Onboarding',
        defaultDescription: 'Automated onboarding checklists, document collection, IT provisioning, and welcoming steps.',
    },
    'hrms.offboarding': {
        key: 'hrms.offboarding',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Core',
        i18nKey: 'hrmsOffboarding',
        defaultLabel: 'Exit & Offboarding',
        defaultDescription: 'Structured resignation workflows, asset handover, departmental clearances, and exit interviews.',
    },

    // HRMS · Time & Attendance
    'hrms.attendance': {
        key: 'hrms.attendance',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Time & Attendance',
        i18nKey: 'hrmsAttendance',
        defaultLabel: 'Time & Attendance',
        defaultDescription: 'Daily check-in and check-out logs, work hours calculation, and regularizations.',
    },
    'hrms.attendance.remote': {
        key: 'hrms.attendance.remote',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Time & Attendance',
        i18nKey: 'hrmsAttendanceRemote',
        defaultLabel: 'Remote Clock-in',
        defaultDescription: 'GPS and IP-verified remote mobile clock-in for distributed and work-from-home staff.',
    },
    'hrms.shifts': {
        key: 'hrms.shifts',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Time & Attendance',
        i18nKey: 'hrmsShifts',
        defaultLabel: 'Shifts & Rosters',
        defaultDescription: 'Create recurring shifts, manage rotational employee rosters, and handle schedule swaps.',
    },
    'hrms.leave': {
        key: 'hrms.leave',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Time & Attendance',
        i18nKey: 'hrmsLeave',
        defaultLabel: 'Leave Management',
        defaultDescription: 'Request paid time off, track annual leave quotas, configure policies, and automate approvals.',
    },
    'hrms.leave.exemption': {
        key: 'hrms.leave.exemption',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Time & Attendance',
        i18nKey: 'hrmsLeaveExemption',
        defaultLabel: 'Leave Policy Exemptions',
        defaultDescription: 'Special administrative overrides for leave entitlement rules and carry-forward balances.',
    },
    'hrms.comp_off': {
        key: 'hrms.comp_off',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Time & Attendance',
        i18nKey: 'hrmsCompOff',
        defaultLabel: 'Compensatory Off',
        defaultDescription: 'Earn and grant compensatory leave credits for extra hours worked during holidays or weekends.',
    },
    'hrms.holidays': {
        key: 'hrms.holidays',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Time & Attendance',
        i18nKey: 'hrmsHolidays',
        defaultLabel: 'Holiday Calendars',
        defaultDescription: 'Configure regional and company-wide public holiday lists, optional leaves, and work weeks.',
    },

    // HRMS · Pay & Benefits
    'hrms.compensation': {
        key: 'hrms.compensation',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Pay & Benefits',
        i18nKey: 'hrmsCompensation',
        defaultLabel: 'Compensation & CTC',
        defaultDescription: 'Structure salaries, pay scales, basic allowances, and variable incentive components.',
    },
    'hrms.expenses': {
        key: 'hrms.expenses',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Pay & Benefits',
        i18nKey: 'hrmsExpenses',
        defaultLabel: 'Expenses & Claims',
        defaultDescription: 'Submit employee expense claims with receipts, apply policy caps, and track reimbursements.',
    },
    'hrms.payroll': {
        key: 'hrms.payroll',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Pay & Benefits',
        i18nKey: 'hrmsPayroll',
        defaultLabel: 'Payroll Processing',
        defaultDescription: 'Run automated monthly payroll batches, calculate net salaries, and generate payslips.',
    },
    'hrms.payroll.statutory': {
        key: 'hrms.payroll.statutory',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Pay & Benefits',
        i18nKey: 'hrmsPayrollStatutory',
        defaultLabel: 'Statutory Compliance',
        defaultDescription: 'Automatic tax deduction calculations (TDS/PAYE), social security, and compliance reports.',
    },

    // HRMS · Performance & Growth
    'hrms.performance': {
        key: 'hrms.performance',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Performance',
        i18nKey: 'hrmsPerformance',
        defaultLabel: 'Goals & OKRs',
        defaultDescription: 'Set quarterly objectives and key results, schedule regular 1-on-1s, and track milestones.',
    },
    'hrms.talent': {
        key: 'hrms.talent',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Performance',
        i18nKey: 'hrmsTalent',
        defaultLabel: '360 Reviews & Talent',
        defaultDescription: 'Run 360-degree performance review cycles, peer evaluations, and 9-box talent calibrations.',
    },
    'hrms.engagement': {
        key: 'hrms.engagement',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Performance',
        i18nKey: 'hrmsEngagement',
        defaultLabel: 'Engagement & Surveys',
        defaultDescription: 'Gather anonymous employee feedback, pulse surveys, eNPS scores, and workplace sentiment.',
    },

    // HRMS · Records & Assets
    'hrms.documents': {
        key: 'hrms.documents',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Records',
        i18nKey: 'hrmsDocuments',
        defaultLabel: 'Document Management',
        defaultDescription: 'Store verified employee contracts, identity proofs, signed agreements, and company policies.',
    },
    'hrms.assets': {
        key: 'hrms.assets',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Records',
        i18nKey: 'hrmsAssets',
        defaultLabel: 'Asset Registry',
        defaultDescription: 'Track company hardware, laptops, peripherals, assigned owners, and maintenance status.',
    },

    // HRMS · Insight & Inbox
    'hrms.analytics': {
        key: 'hrms.analytics',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Insight',
        i18nKey: 'hrmsAnalytics',
        defaultLabel: 'HR Analytics',
        defaultDescription: 'Visual dashboards for headcount growth, attrition rates, leave patterns, and diversity.',
    },
    'hrms.inbox': {
        key: 'hrms.inbox',
        product: 'hrms',
        category: 'hrms',
        group: 'HRMS · Insight',
        i18nKey: 'hrmsInbox',
        defaultLabel: 'Unified HR Inbox',
        defaultDescription: 'Consolidated approval queue for managers to review pending leave, expense, and sign-off requests.',
    },

    // Platform & Integrations
    reports: {
        key: 'reports',
        product: 'platform',
        category: 'platform',
        group: 'Platform',
        i18nKey: 'reports',
        defaultLabel: 'Reports & Analytics',
        defaultDescription: 'Export comprehensive organizational metrics, project progress overviews, and audit histories.',
    },
    global_search: {
        key: 'global_search',
        product: 'platform',
        category: 'platform',
        group: 'Platform',
        i18nKey: 'global_search',
        defaultLabel: 'Global Search',
        defaultDescription: 'Instant cross-workspace search and keyboard command palette across tasks, docs, and members.',
    },
    branding: {
        key: 'branding',
        product: 'platform',
        category: 'platform',
        group: 'Platform',
        i18nKey: 'branding',
        defaultLabel: 'Custom Branding',
        defaultDescription: 'White-label FlowSync with your organization logo, accent palette, and custom workspace styling.',
    },
    'export.full': {
        key: 'export.full',
        product: 'platform',
        category: 'platform',
        group: 'Platform',
        i18nKey: 'exportFull',
        defaultLabel: 'Full Data Export',
        defaultDescription: 'One-click full tenant database and attachment ZIP archive backup download with SHA verification.',
    },
    webhooks: {
        key: 'webhooks',
        product: 'platform',
        category: 'platform',
        group: 'Platform',
        i18nKey: 'webhooks',
        defaultLabel: 'Outbound Webhooks',
        defaultDescription: 'Broadcast signed real-time HTTP event webhooks to external databases and custom backends.',
    },
    api: {
        key: 'api',
        product: 'platform',
        category: 'platform',
        group: 'Platform',
        i18nKey: 'api',
        defaultLabel: 'REST API & Tokens',
        defaultDescription: 'Generate personal API tokens with granular scopes for programmatic integrations and scripts.',
    },
};

export const MODULE_CATEGORIES = [
    { id: 'all', labelKey: 'admin.subscription.categoryAll', defaultLabel: 'All Categories' },
    { id: 'tms', labelKey: 'admin.subscription.categoryTms', defaultLabel: 'Task Management (TMS)' },
    { id: 'hrms', labelKey: 'admin.subscription.categoryHrms', defaultLabel: 'HR Management (HRMS)' },
    { id: 'platform', labelKey: 'admin.subscription.categoryPlatform', defaultLabel: 'Platform & Tools' },
];

/**
 * Returns metadata for a module key, with sensible fallbacks if unfamiliar.
 */
export function getModuleMeta(key) {
    if (MODULE_CATALOG[key]) {
        return MODULE_CATALOG[key];
    }
    const isHrms = key.startsWith('hrms.');
    const label = key
        .replace(/^hrms\./, '')
        .replace(/_/g, ' ')
        .replace(/\./g, ' — ')
        .replace(/\b\w/g, (c) => c.toUpperCase());

    return {
        key,
        product: isHrms ? 'hrms' : 'tms',
        category: isHrms ? 'hrms' : 'tms',
        group: isHrms ? 'HRMS' : 'Platform',
        i18nKey: key,
        defaultLabel: label,
        defaultDescription: `Feature capability: ${label}`,
    };
}

/**
 * Formats human-readable label for a module key with translation support.
 */
export function getLocalizedModuleLabel(t, key) {
    const meta = getModuleMeta(key);
    return t(`admin.subscription.modules.${meta.i18nKey}`, { defaultValue: meta.defaultLabel });
}

/**
 * Formats human-readable description for a module key with translation support.
 */
export function getLocalizedModuleDescription(t, key) {
    const meta = getModuleMeta(key);
    return t(`admin.subscription.moduleDescriptions.${meta.i18nKey}`, { defaultValue: meta.defaultDescription });
}
