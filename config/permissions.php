<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Permission Catalog
    |--------------------------------------------------------------------------
    |
    | The canonical list of permissions available across the application.
    | Every tenant gets its own isolated copy of this catalog, provisioned
    | by the TenantProvisioner whenever a new tenant is created.
    |
    */

    'permissions' => [
        ['name' => 'View Dashboard', 'slug' => 'dashboard.view', 'description' => 'Access the main dashboard'],
        ['name' => 'View Users', 'slug' => 'users.view', 'description' => 'View the user list'],
        ['name' => 'Manage Users', 'slug' => 'users.manage', 'description' => 'Create, edit and delete users'],
        ['name' => 'View Roles', 'slug' => 'roles.view', 'description' => 'View roles and permissions'],
        ['name' => 'Manage Roles', 'slug' => 'roles.manage', 'description' => 'Create and edit roles and their permissions'],
        ['name' => 'View Reports', 'slug' => 'reports.view', 'description' => 'View analytics and reports'],
        ['name' => 'Manage Content', 'slug' => 'content.manage', 'description' => 'Create and edit content'],
        ['name' => 'Customize Theme', 'slug' => 'settings.theme', 'description' => 'Personalize admin panel appearance'],
        ['name' => 'View Settings', 'slug' => 'settings.view', 'description' => 'Access account settings'],
        ['name' => 'View Workspaces', 'slug' => 'workspaces.view', 'description' => 'View workspaces and projects'],
        ['name' => 'Create Workspaces', 'slug' => 'workspaces.create', 'description' => 'Create new workspaces'],
        ['name' => 'Manage Workspaces', 'slug' => 'workspaces.manage', 'description' => 'Edit, archive and manage workspace membership'],

        // HRMS. Grouped by context, ordered as in docs/hrms-implementation-plan.md
        // §3.2. The SENSITIVE/VERY SENSITIVE markers are a review aid, not code:
        // what actually protects these is the policy layer (D2.12) plus the
        // `hrms.*` module gate on the route group (D2.13).
        ['name' => 'View HRMS', 'slug' => 'hrms.view', 'description' => 'Reach the HRMS surfaces'],

        ['name' => 'View Employees', 'slug' => 'hrms.employees.view', 'description' => 'View the employee directory and profiles'],
        ['name' => 'Manage Employees', 'slug' => 'hrms.employees.manage', 'description' => 'Create and edit employee records'],
        ['name' => 'View Org Structure', 'slug' => 'hrms.org.view', 'description' => 'View departments, designations and locations'],
        ['name' => 'Manage Org Structure', 'slug' => 'hrms.org.manage', 'description' => 'Manage departments, designations and locations'],

        ['name' => 'View Onboarding', 'slug' => 'hrms.onboarding.view', 'description' => 'View onboarding cases'],
        ['name' => 'Manage Onboarding', 'slug' => 'hrms.onboarding.manage', 'description' => 'Create and run onboarding cases'],
        ['name' => 'View Offboarding', 'slug' => 'hrms.offboarding.view', 'description' => 'View offboarding cases'],
        ['name' => 'Manage Offboarding', 'slug' => 'hrms.offboarding.manage', 'description' => 'Run offboarding and sign off clearance'],

        ['name' => 'View Attendance', 'slug' => 'hrms.attendance.view', 'description' => 'View attendance records'],
        ['name' => 'Manage Attendance', 'slug' => 'hrms.attendance.manage', 'description' => 'Edit and delete punches and day records'],
        ['name' => 'Regularize Attendance', 'slug' => 'hrms.attendance.regularize', 'description' => 'Approve attendance regularization requests'],
        ['name' => 'Configure Attendance', 'slug' => 'hrms.attendance.settings', 'description' => 'Configure shifts, rosters and clock-in policy'],

        ['name' => 'View Leave', 'slug' => 'hrms.leave.view', 'description' => 'View leave types, policies and balances'],
        ['name' => 'Manage Leave', 'slug' => 'hrms.leave.manage', 'description' => 'Manage leave types, policies and balances'],
        ['name' => 'Approve Leave', 'slug' => 'hrms.leave.approve', 'description' => 'Approve and reject leave requests'],

        ['name' => 'View Comp-Off', 'slug' => 'hrms.comp_off.view', 'description' => 'View comp-off credits and balances'],
        ['name' => 'Approve Comp-Off', 'slug' => 'hrms.comp_off.approve', 'description' => 'Approve and reject comp-off requests'],
        ['name' => 'Manage Comp-Off', 'slug' => 'hrms.comp_off.manage', 'description' => 'Configure comp-off policy'],

        ['name' => 'View Shifts', 'slug' => 'hrms.shifts.view', 'description' => 'View shift patterns and rosters'],
        ['name' => 'Manage Shifts', 'slug' => 'hrms.shifts.manage', 'description' => 'Configure shift patterns and rosters'],
        ['name' => 'View Holidays', 'slug' => 'hrms.holidays.view', 'description' => 'View holiday calendars'],
        ['name' => 'Manage Holidays', 'slug' => 'hrms.holidays.manage', 'description' => 'Manage holiday calendars'],

        ['name' => 'View Expenses', 'slug' => 'hrms.expenses.view', 'description' => 'View expense claims'],
        ['name' => 'Approve Expenses', 'slug' => 'hrms.expenses.approve', 'description' => 'Approve and reject expense claims'],
        ['name' => 'Manage Expenses', 'slug' => 'hrms.expenses.manage', 'description' => 'Manage expense categories and policy'],

        ['name' => 'View Compensation', 'slug' => 'hrms.compensation.view', 'description' => 'View salary structures and letters'], // SENSITIVE
        ['name' => 'Manage Compensation', 'slug' => 'hrms.compensation.manage', 'description' => 'Change salary structures and revisions'], // SENSITIVE

        ['name' => 'View Payroll', 'slug' => 'hrms.payroll.view', 'description' => 'View payroll runs and own payslip'],
        ['name' => 'View All Payslips', 'slug' => 'hrms.payroll.view_all', 'description' => "View every employee's payslip"], // SENSITIVE
        ['name' => 'Run Payroll', 'slug' => 'hrms.payroll.run', 'description' => 'Create, run, approve and disburse payroll'], // SENSITIVE
        ['name' => 'Manage Payroll', 'slug' => 'hrms.payroll.manage', 'description' => 'Manage salary components and structures'], // SENSITIVE
        ['name' => 'View Statutory', 'slug' => 'hrms.payroll.statutory.view', 'description' => 'View statutory config, projections and masked ids'],
        ['name' => 'Manage Statutory', 'slug' => 'hrms.payroll.statutory.manage', 'description' => 'Configure statutory rules and employee ids'], // VERY SENSITIVE

        ['name' => 'View Performance', 'slug' => 'hrms.performance.view', 'description' => 'View cycles, goals and reviews'],
        ['name' => 'Manage Performance', 'slug' => 'hrms.performance.manage', 'description' => 'Create cycles and manage goals'],
        ['name' => 'View Talent', 'slug' => 'hrms.talent.view', 'description' => 'View reviews and ratings per anonymity policy'],
        ['name' => 'Manage Talent', 'slug' => 'hrms.talent.manage', 'description' => 'Run review cycles and calibrate'],
        ['name' => 'View Engagement', 'slug' => 'hrms.engagement.view', 'description' => 'View surveys and results'],
        ['name' => 'Manage Engagement', 'slug' => 'hrms.engagement.manage', 'description' => 'Create surveys and campaigns'],

        ['name' => 'View Documents', 'slug' => 'hrms.documents.view', 'description' => 'View employee documents'],
        ['name' => 'Manage Documents', 'slug' => 'hrms.documents.manage', 'description' => 'Upload, verify and expire documents'],
        ['name' => 'View Sensitive Documents', 'slug' => 'hrms.documents.view_sensitive', 'description' => 'View confidential, bank and statutory documents'], // SENSITIVE

        ['name' => 'View Assets', 'slug' => 'hrms.assets.view', 'description' => 'View the asset register'],
        ['name' => 'Manage Assets', 'slug' => 'hrms.assets.manage', 'description' => 'Manage assets and assignments'],

        ['name' => 'View HR Analytics', 'slug' => 'hrms.analytics.view', 'description' => 'View HR analytics dashboards'],
        ['name' => 'View HRMS Audit Log', 'slug' => 'hrms.audit.view', 'description' => 'View the HRMS audit log'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Roles
    |--------------------------------------------------------------------------
    |
    | Roles provisioned in each tenant. 'admin' receives every permission,
    | the remaining roles receive the listed subset.
    |
    | A role's `permissions` is a list of *selectors* resolved against the
    | catalog above by App\Support\PermissionSelector at provision time:
    |
    |   '*'           every permission in the catalog
    |   'hrms.*'      every slug starting with "hrms." (prefix glob)
    |   '!hrms.payroll.*'  subtract a previously selected group
    |   'hrms.view'   an exact slug
    |
    | Selectors (not literal slug lists) matter for the HRMS roles: a new phase
    | adding `hrms.foo.manage` must reach `hr_manager` automatically. The
    | resolved slugs are snapshotted onto the role, so a tenant's permissions
    | never change because config changed — only a re-provision applies it.
    |
    */

    'roles' => [
        'admin' => [
            'name' => 'Administrator',
            'permissions' => '*',
        ],
        'editor' => [
            'name' => 'Editor',
            'permissions' => [
                'dashboard.view', 'users.view', 'reports.view',
                'content.manage', 'settings.theme', 'settings.view',
                'workspaces.view', 'workspaces.create',
                'hrms.view',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'permissions' => [
                'dashboard.view', 'reports.view', 'settings.theme', 'settings.view',
                'workspaces.view',
                'hrms.view',
            ],
        ],
        // Every hrms.* permission except the payroll surface and the salary
        // mutation permission. `!hrms.payroll.*` also subtracts
        // `hrms.payroll.statutory.*` (prefix glob), so HR managers can read
        // attendance/leave but never touch a payslip or a statutory id.
        'hr_manager' => [
            'name' => 'HR Manager',
            'permissions' => [
                'dashboard.view', 'users.view', 'reports.view', 'workspaces.view',
                'hrms.*',
                '!hrms.payroll.*',
                '!hrms.compensation.manage',
            ],
        ],
        // The payroll surface plus everything payroll needs to read to compute
        // a payslip. Deliberately has no org/employee/asset management rights.
        'payroll_manager' => [
            'name' => 'Payroll Manager',
            'permissions' => [
                'dashboard.view', 'users.view', 'workspaces.view',
                'hrms.view',
                'hrms.employees.view',
                'hrms.documents.view',
                'hrms.documents.view_sensitive',
                'hrms.payroll.*',
                'hrms.compensation.*',
                'hrms.attendance.*',
                'hrms.leave.*',
                'hrms.expenses.*',
            ],
        ],
    ],
];
