<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription catalog
    |--------------------------------------------------------------------------
    | Machine-readable feature flags + numeric limits used by the TenantLimits
    | service (§5.3). Seeded into `subscription_plans` by SubscriptionPlanSeeder.
    */

    // Feature modules a tenant can toggle on/off.
    'modules' => [
        'time_tracking',
        'reports',
        'global_search',
        'api',
        'branding',
        'audit_export',

        // HRMS. Dotted `hrms.*` keys are flat leaves: `TenantLimits::hasModule()`
        // is a string compare, so a sub-feature such as `hrms.attendance.remote`
        // gates independently of its parent. The tree shown in Feature Management
        // is derived by splitting on '.' (see docs/hrms-implementation-plan.md
        // D2.1). The authoritative list with descriptions lives in Part 3.1 of
        // the plan; keep the two in sync.
        'hrms.core',
        'hrms.onboarding',
        'hrms.offboarding',
        'hrms.attendance',
        'hrms.attendance.remote',
        'hrms.shifts',
        'hrms.leave',
        'hrms.leave.exemption',
        'hrms.comp_off',
        'hrms.holidays',
        'hrms.expenses',
        'hrms.compensation',
        'hrms.payroll',
        'hrms.payroll.statutory',
        'hrms.performance',
        'hrms.talent',
        'hrms.engagement',
        'hrms.documents',
        'hrms.assets',
        'hrms.inbox',
        'hrms.analytics',
        'hrms.exemptions',
    ],

    // Display metadata for the module catalog: slug => label + group. The keys
    // here must stay identical to the `modules` list above (asserted by
    // ModuleCatalogTest) — that list stays flat because TenantLimits and the
    // middleware compare it as strings, while the admin grid and the HRMS
    // overview render it as a tree. Keeping the labels here (not in a JS map)
    // means a module cannot be added without a human-readable name.
    'module_meta' => [
        'time_tracking' => ['label' => 'Time Tracking', 'group' => 'Platform'],
        'reports' => ['label' => 'Reports', 'group' => 'Platform'],
        'global_search' => ['label' => 'Global Search', 'group' => 'Platform'],
        'api' => ['label' => 'API Access', 'group' => 'Platform'],
        'branding' => ['label' => 'Branding', 'group' => 'Platform'],
        'audit_export' => ['label' => 'Audit Export', 'group' => 'Platform'],

        'hrms.core' => ['label' => 'Employee Records', 'group' => 'HRMS · Core'],
        'hrms.onboarding' => ['label' => 'Onboarding', 'group' => 'HRMS · Core'],
        'hrms.offboarding' => ['label' => 'Offboarding', 'group' => 'HRMS · Core'],

        'hrms.attendance' => ['label' => 'Attendance', 'group' => 'HRMS · Time & Attendance'],
        'hrms.attendance.remote' => ['label' => 'Remote Clock-in', 'group' => 'HRMS · Time & Attendance'],
        'hrms.shifts' => ['label' => 'Shifts & Rosters', 'group' => 'HRMS · Time & Attendance'],
        'hrms.leave' => ['label' => 'Leave', 'group' => 'HRMS · Time & Attendance'],
        'hrms.leave.exemption' => ['label' => 'Leave Exemptions', 'group' => 'HRMS · Time & Attendance'],
        'hrms.comp_off' => ['label' => 'Comp-Off', 'group' => 'HRMS · Time & Attendance'],
        'hrms.holidays' => ['label' => 'Holidays', 'group' => 'HRMS · Time & Attendance'],

        'hrms.compensation' => ['label' => 'Compensation', 'group' => 'HRMS · Pay & Benefits'],
        'hrms.expenses' => ['label' => 'Expenses & Claims', 'group' => 'HRMS · Pay & Benefits'],
        'hrms.payroll' => ['label' => 'Payroll', 'group' => 'HRMS · Pay & Benefits'],
        'hrms.payroll.statutory' => ['label' => 'Statutory Deductions', 'group' => 'HRMS · Pay & Benefits'],
        'hrms.exemptions' => ['label' => 'Tax Exemptions', 'group' => 'HRMS · Pay & Benefits'],

        'hrms.performance' => ['label' => 'Performance Goals', 'group' => 'HRMS · Performance'],
        'hrms.talent' => ['label' => 'Reviews & Talent', 'group' => 'HRMS · Performance'],
        'hrms.engagement' => ['label' => 'Engagement', 'group' => 'HRMS · Performance'],

        'hrms.documents' => ['label' => 'Documents', 'group' => 'HRMS · Records'],
        'hrms.assets' => ['label' => 'Assets', 'group' => 'HRMS · Records'],

        'hrms.analytics' => ['label' => 'HR Analytics', 'group' => 'HRMS · Insight'],
        'hrms.inbox' => ['label' => 'HR Inbox', 'group' => 'HRMS · Insight'],
    ],

    // Numeric limits enforced via TenantLimits::assertQuota() (all optional).
    'limits' => [
        'users',
        'seats',                 // alias of users; set when a plan only sells seats
        'workspaces',
        'projects',
        'tasks',
        'storage_bytes',
        'attachments_per_task',
        'employees',             // HRMS headcount
        'assets',                // HRMS asset register
        'hr_document_bytes',     // HRMS document storage
    ],

    'currency' => env('SUBSCRIPTIONS_CURRENCY', 'USD'),
    'default_trial_days' => (int) env('SUBSCRIPTIONS_DEFAULT_TRIAL_DAYS', 14),

    // Default plans (slug → attributes). `limits` keys: modules list + numeric
    // limits above; a missing numeric key means "unlimited".
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'description' => 'Small teams getting organized.',
            'is_active' => true,
            'is_default' => true,
            'billing_cycle' => 'monthly',
            'price_cents' => 0,
            'trial_duration_days' => 14,
            'sort_order' => 10,
            'limits' => [
                'users' => 5,
                'workspaces' => 2,
                'projects' => 10,
                'tasks' => 500,
                'storage_bytes' => 5 * 1024 * 1024 * 1024,      // 5 GB
                'attachments_per_task' => 5,
                // No `hrms.*` module: HRMS is entirely absent from Starter.
                'modules' => ['time_tracking'],
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'description' => 'Growing teams with reporting and search.',
            'is_active' => true,
            'is_default' => false,
            'billing_cycle' => 'monthly',
            'price_cents' => 2900,                              // $29.00
            'trial_duration_days' => null,                      // no trial
            'sort_order' => 20,
            'limits' => [
                'users' => 50,
                'workspaces' => 20,
                'projects' => 200,
                'tasks' => 10000,
                'storage_bytes' => 50 * 1024 * 1024 * 1024,     // 50 GB
                'attachments_per_task' => 25,
                // Plan B HRMS set — see docs/hrms-implementation-plan.md §3.1.
                'modules' => [
                    'time_tracking', 'reports', 'global_search',
                    'hrms.core', 'hrms.documents', 'hrms.onboarding', 'hrms.offboarding',
                    'hrms.assets', 'hrms.attendance', 'hrms.attendance.remote', 'hrms.leave',
                    'hrms.comp_off', 'hrms.holidays', 'hrms.shifts', 'hrms.inbox',
                ],
            ],
        ],

        'business' => [
            'name' => 'Business',
            'description' => 'Full people operations for growing organizations.',
            'is_active' => true,
            'is_default' => false,
            'billing_cycle' => 'monthly',
            'price_cents' => 7900,                              // $79.00
            'trial_duration_days' => null,
            'sort_order' => 25,
            'limits' => [
                'users' => 200,
                'employees' => 200,
                'workspaces' => 100,
                'projects' => 500,
                'tasks' => 50000,
                'storage_bytes' => 200 * 1024 * 1024 * 1024,   // 200 GB
                'hr_document_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
                'assets' => 1000,
                'attachments_per_task' => 50,
                // Plan B HRMS set + Plan C (compensation, expenses, performance,
                // talent, engagement, analytics). No payroll: that is Enterprise.
                'modules' => [
                    'time_tracking', 'reports', 'global_search',
                    'hrms.core', 'hrms.documents', 'hrms.onboarding', 'hrms.offboarding',
                    'hrms.assets', 'hrms.attendance', 'hrms.attendance.remote', 'hrms.leave',
                    'hrms.comp_off', 'hrms.holidays', 'hrms.shifts', 'hrms.inbox',
                    'hrms.compensation', 'hrms.expenses', 'hrms.performance', 'hrms.talent',
                    'hrms.engagement', 'hrms.analytics',
                ],
            ],
        ],

        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Full platform for organizations.',
            'is_active' => true,
            'is_default' => false,
            'billing_cycle' => 'annual',
            'price_cents' => 9900,                              // $99.00/mo billed annually
            'trial_duration_days' => null,
            'sort_order' => 30,
            'limits' => [
                'users' => 1000,
                'workspaces' => 500,
                'projects' => 5000,
                'tasks' => 1000000,
                'storage_bytes' => 500 * 1024 * 1024 * 1024,    // 500 GB
                'attachments_per_task' => 100,
                'employees' => 1000,
                'assets' => 10000,
                'hr_document_bytes' => 500 * 1024 * 1024 * 1024,  // 500 GB
                // Everything: the Plan B set + Plan C + payroll + statutory +
                // exemption + the platform's own modules.
                'modules' => [
                    'time_tracking', 'reports', 'global_search', 'api', 'branding', 'audit_export',
                    'hrms.core', 'hrms.documents', 'hrms.onboarding', 'hrms.offboarding',
                    'hrms.assets', 'hrms.attendance', 'hrms.attendance.remote', 'hrms.leave',
                    'hrms.comp_off', 'hrms.holidays', 'hrms.shifts', 'hrms.inbox',
                    'hrms.compensation', 'hrms.expenses', 'hrms.performance', 'hrms.talent',
                    'hrms.engagement', 'hrms.analytics',
                    'hrms.payroll', 'hrms.payroll.statutory', 'hrms.leave.exemption', 'hrms.exemptions',
                ],
            ],
        ],
    ],
];
