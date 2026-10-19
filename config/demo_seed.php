<?php

/*
|--------------------------------------------------------------------------
| Realistic demo dataset (development / demo only — never production)
|--------------------------------------------------------------------------
|
| Drives `php artisan tenants:seed-realistic` (database/seeders/Realistic/*).
| Every size can be overridden from the environment, so a laptop can run a
| 6-tenant slice (DEMO_SEED_TENANTS_HRMS=2 …) and a server the full 150.
|
*/

return [
    // Bump when the generated shape changes: tenants seeded at an older version are re-seeded with --reseed.
    'version' => 1,

    // Every random draw is derived from this plus the tenant's index, so a tenant is reproducible
    // regardless of how many workers run or in what order.
    'seed' => (int) env('DEMO_SEED_SEED', 20261018),

    // Tenant counts per category: hrms = HRMS-only, tms = TMS-only, both = HRMS + TMS.
    'tenants' => [
        'hrms' => (int) env('DEMO_SEED_TENANTS_HRMS', 50),
        'tms' => (int) env('DEMO_SEED_TENANTS_TMS', 50),
        'both' => (int) env('DEMO_SEED_TENANTS_BOTH', 50),
    ],

    'users' => ['min' => (int) env('DEMO_SEED_USERS_MIN', 25), 'max' => (int) env('DEMO_SEED_USERS_MAX', 200)],
    'projects' => ['min' => (int) env('DEMO_SEED_PROJECTS_MIN', 20), 'max' => (int) env('DEMO_SEED_PROJECTS_MAX', 30)],
    'tasks_per_project' => ['min' => (int) env('DEMO_SEED_TASKS_MIN', 150), 'max' => (int) env('DEMO_SEED_TASKS_MAX', 160)],

    // HRMS history depth.
    'attendance_days' => (int) env('DEMO_SEED_ATTENDANCE_DAYS', 45),
    'payroll_months' => (int) env('DEMO_SEED_PAYROLL_MONTHS', 3),

    // All demo logins share this password (hashed once per run).
    'password' => 'password',
];
