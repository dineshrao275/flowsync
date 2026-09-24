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
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Roles
    |--------------------------------------------------------------------------
    |
    | Roles provisioned in each tenant. 'admin' receives every permission,
    | the remaining roles receive the listed subset.
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
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'permissions' => [
                'dashboard.view', 'reports.view', 'settings.theme', 'settings.view',
                'workspaces.view',
            ],
        ],
    ],
];
