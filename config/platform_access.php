<?php

/*
 | Platform (super admin side) access model (P8.3).
 |
 | Every platform account is an `is_super_admin` user in the system DB. An
 | account with NO persona role is the unrestricted break-glass admin (exactly
 | the old behaviour). Assigning one or more personas NARROWS the account to the
 | union of their permissions; anything not covered answers 403 naming the
 | missing permission. Fail-closed: a platform route with no entry in `routes`
 | is denied to every restricted account (PlatformAccessTest pins that every
 | super_admin route is mapped).
 */
return [
    'permissions' => [
        'tenants.view' => 'See tenants, their users, usage and onboarding state',
        'tenants.manage' => 'Create, edit, suspend, restore and delete tenants',
        'billing.view' => 'See plans, subscriptions and the module-by-plan grid',
        'billing.manage' => 'Change plans, subscriptions, trials and module entitlements',
        'support.view' => 'Read support tickets',
        'support.manage' => 'Reply to, update and close support tickets',
        'impersonate.start' => 'Sign in as a tenant user (with a reason, time-boxed)',
        'audit.view' => 'Read the platform audit trail',
        'audit.export' => 'Export the platform audit trail',
        'analytics.view' => 'See platform analytics and health',
        'flags.view' => 'See runtime feature flags',
        'flags.manage' => 'Change feature flags and tenant overrides',
        'content.view' => 'See marketing site pages',
        'content.manage' => 'Edit and publish marketing site pages',
        'settings.view' => 'See platform settings',
        'settings.manage' => 'Change platform settings and security policy',
        'platform_users.view' => 'See platform accounts and their personas',
        'platform_users.manage' => 'Create platform accounts and assign personas',
    ],

    'personas' => [
        'billing_admin' => [
            'name' => 'Billing Admin',
            'description' => 'Plans, subscriptions and entitlements; read-only on tenants.',
            'permissions' => ['tenants.view', 'billing.view', 'billing.manage', 'analytics.view'],
        ],
        'support' => [
            'name' => 'Support',
            'description' => 'Answers tickets and, with a reason, signs in as tenant users.',
            'permissions' => ['tenants.view', 'support.view', 'support.manage', 'impersonate.start'],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Read-only across the platform, including the audit trail and its export.',
            'permissions' => ['tenants.view', 'billing.view', 'support.view', 'audit.view', 'audit.export', 'analytics.view', 'flags.view', 'content.view', 'settings.view', 'platform_users.view'],
        ],
        'operator' => [
            'name' => 'Operator',
            'description' => 'Runs the fleet: tenant lifecycle, health, feature flags; no billing or accounts.',
            'permissions' => ['tenants.view', 'tenants.manage', 'analytics.view', 'flags.view', 'flags.manage', 'settings.view', 'content.view'],
        ],
    ],

    /*
     | Route -> permission map. First match wins. Entries:
     | [uri pattern (Str::is against the route's URI template), read slug, write slug].
     | GET/HEAD use the read slug, every other verb the write slug.
     */
    'routes' => [
        ['api/impersonate', 'impersonate.start', 'impersonate.start'],
        ['api/system/support-access/policy', 'settings.view', 'settings.manage'],
        ['api/system/support-access*', 'support.view', 'support.manage'],
        ['api/system/support/*', 'support.view', 'support.manage'],
        ['api/system/audit-logs/export', 'audit.export', 'audit.export'],
        ['api/system/audit-logs*', 'audit.view', 'audit.view'],
        ['api/system/analytics', 'analytics.view', 'analytics.view'],
        ['api/platform/health', 'analytics.view', 'analytics.view'],
        ['api/system/health', 'analytics.view', 'analytics.view'],
        ['api/system/features*', 'billing.view', 'billing.manage'],
        ['api/plans*', 'billing.view', 'billing.manage'],
        ['api/tenants/{tenant}/subscription*', 'billing.view', 'billing.manage'],
        ['api/tenants/{tenant}/products', 'billing.view', 'billing.manage'],
        ['api/system/feature-flags*', 'flags.view', 'flags.manage'],
        ['api/system/pages*', 'content.view', 'content.manage'],
        ['api/system/settings', 'settings.view', 'settings.manage'],
        ['api/system/two-factor-policy', 'settings.view', 'settings.manage'],
        ['api/system/users*', 'platform_users.view', 'platform_users.manage'],
        ['api/system/platform-roles*', 'platform_users.view', 'platform_users.manage'],
        ['api/tenants*', 'tenants.view', 'tenants.manage'],
    ],
];
