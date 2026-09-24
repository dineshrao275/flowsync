<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Priority Catalog
    |--------------------------------------------------------------------------
    |
    | The default priorities provisioned for every tenant. Tenants may rename,
    | recolor, reorder or add their own priorities at runtime; these rows are
    | seeded idempotently by the TenantProvisioner.
    |
    */

    'default_slug' => 'medium',

    'priorities' => [
        ['name' => 'Highest', 'slug' => 'highest', 'value' => 5, 'color' => '#ef4444', 'position' => 5],
        ['name' => 'High', 'slug' => 'high', 'value' => 4, 'color' => '#f97316', 'position' => 4],
        ['name' => 'Medium', 'slug' => 'medium', 'value' => 3, 'color' => '#eab308', 'position' => 3],
        ['name' => 'Low', 'slug' => 'low', 'value' => 2, 'color' => '#3b82f6', 'position' => 2],
        ['name' => 'Lowest', 'slug' => 'lowest', 'value' => 1, 'color' => '#6b7280', 'position' => 1],
    ],
];
