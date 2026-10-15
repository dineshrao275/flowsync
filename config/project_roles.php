<?php

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
| Scope variants
| --------------
| A project permission that gates ROWS gets the same three suffixes the
| tenant catalog uses (config/permissions.php → scopes), so one
| App\Support\PermissionScope resolves both catalogs:
|
|   tasks.view_own       tasks assigned to you
|   tasks.view_assigned  yours plus your direct reports'
|   tasks.view_all       every task in the project
|
| Only `tasks` declares them. The other project permissions are actions
| (create a task, upload an attachment) or already answer "owner OR
| permission" — comments, work logs and attachments are theirs by right
| of ownership today, so a `_own` variant would promise self-service the
| owner rule already grants and an `_all` variant would promise nothing
| new. Adding a variant where ownership already decides just gives the
| Roles screen a second knob for the same thing.
|
| The unsuffixed legacy slug keeps working and means `_all`, so a project
| role holding `tasks.view` today reads exactly what it read before.
|
*/

// Row-gated permission => the verbs that gate its rows.
$scopeDomains = [
    'tasks' => ['view', 'edit', 'delete', 'move', 'assign'],
];

$scopeLabels = ['own' => 'Own', 'assigned' => 'Own & Team', 'all' => 'All'];

$scopeText = [
    'own' => 'only tasks assigned to you',
    'assigned' => 'your tasks plus the tasks of your direct reports',
    'all' => 'every task in the project, for every member',
];

$permissions = [
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
];

foreach ($scopeDomains as $domain => $verbs) {
    foreach ($verbs as $verb) {
        foreach (array_keys($scopeLabels) as $scope) {
            $permissions[] = "{$domain}.{$verb}_{$scope}";
        }
    }
}

return [
    'permissions' => $permissions,

    // Which bases carry a self concept, for tests and the Roles screen.
    'scope_domains' => $scopeDomains,

    'scope_text' => $scopeText,

    /*
    |--------------------------------------------------------------------------
    | Default Project Roles
    |--------------------------------------------------------------------------
    |
    | System roles seeded into each tenant. 'lead' receives every project
    | permission; the remaining roles receive the listed subset. Custom roles
    | are created by tenant admins at runtime.
    |
    | The default roles deliberately keep the UNSCOPED slugs: legacy means
    | `_all`, so developer and viewer read exactly what they read before the
    | variants existed. Narrowing a team is what a custom role is for.
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
