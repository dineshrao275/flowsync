<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Project Permission Catalog
    |--------------------------------------------------------------------------
    |
    | Capabilities assignable to a project role. These are scoped to an
    | individual project (unlike the tenant-level permissions in
    | config/permissions.php). The TenantProvisioner clones the default
    | roles below into every tenant.
    |
    */

    'permissions' => [
        'projects.view',
        'projects.edit',
        'projects.delete',
        'projects.settings',
        'members.manage',
        'tasks.view',
        'tasks.create',
        'tasks.edit',
        'tasks.delete',
        'tasks.assign',
        'tasks.move',
        'comments.create',
        'comments.edit',
        'comments.delete',
        'attachments.create',
        'attachments.delete',
        'work_logs.create',
        'work_logs.edit',
        'work_logs.delete',
        'work_logs.manage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Project Roles
    |--------------------------------------------------------------------------
    |
    | System roles seeded into each tenant. 'lead' receives every project
    | permission; the remaining roles receive the listed subset. Custom roles
    | are created by tenant admins at runtime.
    |
    */

    'roles' => [
        'lead' => [
            'name' => 'Project Lead',
            'permissions' => '*',
        ],
        'developer' => [
            'name' => 'Developer',
            'permissions' => [
                'projects.view', 'tasks.view', 'tasks.create', 'tasks.edit',
                'tasks.assign', 'tasks.move', 'comments.create', 'comments.edit',
                'comments.delete', 'attachments.create', 'work_logs.create',
                'work_logs.edit', 'work_logs.delete',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'permissions' => [
                'projects.view',
                'tasks.view',
                'comments.create',
            ],
        ],
    ],
];
