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
                'modules' => ['time_tracking', 'reports', 'global_search'],
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
                'modules' => ['time_tracking', 'reports', 'global_search', 'api', 'branding', 'audit_export'],
            ],
        ],
    ],
];
