/**
 * Human-readable names for the HRMS module catalog.
 *
 * `user.modules` from `AuthController::payload()` is a flat array of keys; this
 * turns it into something renderable. The map is generated from
 * `config/subscriptions.php` → `module_meta`, and
 * `tests/Feature/HrmsShellTest.php::test_the_spa_catalog_matches_the_php_module_catalog`
 * fails if the two drift, so a module cannot be added to the catalog without a
 * name and a group (D2.1).
 */
/** Kept last in this file: `HRMS_MODULE_ROUTES` calls it while the module lives below. */
function hrmsUrl(section) {
    return section ? `/hrms/${section}` : '/hrms';
}

export const HRMS_MODULE_META = {
    'hrms.core': { label: 'Employee Records', group: 'HRMS · Core' },
    'hrms.onboarding': { label: 'Onboarding', group: 'HRMS · Core' },
    'hrms.offboarding': { label: 'Offboarding', group: 'HRMS · Core' },

    'hrms.attendance': { label: 'Attendance', group: 'HRMS · Time & Attendance' },
    'hrms.attendance.remote': { label: 'Remote Clock-in', group: 'HRMS · Time & Attendance' },
    'hrms.shifts': { label: 'Shifts & Rosters', group: 'HRMS · Time & Attendance' },
    'hrms.leave': { label: 'Leave', group: 'HRMS · Time & Attendance' },
    'hrms.leave.exemption': { label: 'Leave Exemptions', group: 'HRMS · Time & Attendance' },
    'hrms.comp_off': { label: 'Comp-Off', group: 'HRMS · Time & Attendance' },
    'hrms.holidays': { label: 'Holidays', group: 'HRMS · Time & Attendance' },

    'hrms.expenses': { label: 'Expenses & Claims', group: 'HRMS · Pay & Benefits' },
    'hrms.compensation': { label: 'Compensation', group: 'HRMS · Pay & Benefits' },
    'hrms.payroll': { label: 'Payroll', group: 'HRMS · Pay & Benefits' },
    'hrms.payroll.statutory': { label: 'Statutory Deductions', group: 'HRMS · Pay & Benefits' },
    'hrms.exemptions': { label: 'Tax Exemptions', group: 'HRMS · Pay & Benefits' },

    'hrms.performance': { label: 'Performance Goals', group: 'HRMS · Performance' },
    'hrms.talent': { label: 'Reviews & Talent', group: 'HRMS · Performance' },
    'hrms.engagement': { label: 'Engagement', group: 'HRMS · Performance' },

    'hrms.documents': { label: 'Documents', group: 'HRMS · Records' },
    'hrms.assets': { label: 'Assets', group: 'HRMS · Records' },

    'hrms.analytics': { label: 'HR Analytics', group: 'HRMS · Insight' },
    'hrms.inbox': { label: 'HR Inbox', group: 'HRMS · Insight' },
};

/**
 * The SPA route each module leads to, once its phase has landed.
 *
 * Only populated for modules that actually have a route. A module with no entry
 * renders as a non-interactive tile rather than a link, so the overview never
 * sends a user to a URL the router does not know.
 */
export const HRMS_MODULE_ROUTES = {
    // P2.5 ships the employee directory; the rest of `hrms.core` (onboarding,
    // offboarding) lands with its own phases.
    'hrms.core': hrmsUrl('employees'),
    // P13.4 ships the document store; the tile must point at a route the
    // router owns (see the shell test), not at a module with no page.
    'hrms.documents': hrmsUrl('documents'),
    // P4.4 ships the lifecycle runs; same rule — no tile without a route.
    'hrms.onboarding': hrmsUrl('onboarding'),
    'hrms.offboarding': hrmsUrl('offboarding'),
    // P5.6b ships the attendance workspace; the remote clock-in capability
    // stays tile-less (it is a way to punch, not a page).
    'hrms.attendance': hrmsUrl('attendance'),
    // P6.5 ships the leave admin hub; the self-service page needs no tile
    // (it hangs off the hub like My files hangs off the store).
    'hrms.leave': hrmsUrl('leave'),
    // P7.4 ships the comp-off hub on the same split.
    'hrms.comp_off': hrmsUrl('comp-off'),
    // P8.4c ships the holiday calendars; the tile points at the page, and
    // the page gates its own mutations.
    'hrms.holidays': hrmsUrl('holidays'),
    // P18.5 ships the workforce dashboards; the tile points at the page,
    // and the tabs gate themselves per domain.
    'hrms.analytics': hrmsUrl('analytics'),
};

/**
 * The tabs on an employee profile, with the module each one depends on.
 *
 * Only a section that has *shipped* gets an entry, on the same principle as
 * `HRMS_MODULE_ROUTES`: a tab whose backend does not exist yet renders as a
 * dead end, and a profile with six "coming soon" tabs is worse than one with
 * none. A tab is added here as its phase lands.
 */
export const HRMS_PROFILE_TABS = {
    overview: { label: 'Overview', module: 'hrms.core' },
    // P13.4 ships the profile’s documents tab: a filtered store for this
    // person, gated on the module like every other tab.
    documents: { label: 'Documents', module: 'hrms.documents' },
};

/**
 * The tabs a caller may see, in catalog order, filtered to their plan.
 *
 * @param {string[]} modules
 * @returns {{ key: string, label: string }[]}
 */
export function hrmsProfileTabs(modules = []) {
    const enabled = new Set(modules);

    return Object.entries(HRMS_PROFILE_TABS)
        .filter(([, tab]) => !tab.module || enabled.has(tab.module))
        .map(([key, tab]) => ({ key, label: tab.label }));
}

/**
 * Group enabled modules for rendering, preserving catalog order.
 *
 * @param {string[]} modules
 * @returns {{ group: string, modules: { key: string, label: string, to: string|null }[] }[]}
 */
export function hrmsModuleGroups(modules = []) {
    const enabled = new Set(modules);
    const groups = new Map();

    for (const [key, meta] of Object.entries(HRMS_MODULE_META)) {
        if (!enabled.has(key)) continue;

        if (!groups.has(meta.group)) groups.set(meta.group, []);

        groups.get(meta.group).push({
            key,
            label: meta.label,
            to: HRMS_MODULE_ROUTES[key] ?? null,
        });
    }

    return [...groups.entries()].map(([group, items]) => ({ group, modules: items }));
}

/** @param {string[]} modules */
export function hrmsModuleLabel(key, modules = []) {
    if (!modules.includes(key)) return null;
    return HRMS_MODULE_META[key]?.label ?? null;
}
