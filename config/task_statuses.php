<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Task Statuses
    |--------------------------------------------------------------------------
    |
    | The statuses seeded into every new project (via ProjectService). Each
    | project gets its own copy so its workflow can be renamed, reordered,
    | recolored and extended independently. The `is_default` row is the
    | initial status for newly created tasks.
    |
    */

    'statuses' => [
        ['name' => 'Backlog', 'slug' => 'backlog', 'category' => 'backlog', 'position' => 1, 'color' => '#64748b', 'is_done' => false],
        ['name' => 'To Do', 'slug' => 'to-do', 'category' => 'todo', 'position' => 2, 'color' => '#3b82f6', 'is_done' => false, 'is_default' => true],
        ['name' => 'In Progress', 'slug' => 'in-progress', 'category' => 'in_progress', 'position' => 3, 'color' => '#f59e0b', 'is_done' => false],
        ['name' => 'In Review', 'slug' => 'in-review', 'category' => 'in_review', 'position' => 4, 'color' => '#8b5cf6', 'is_done' => false],
        ['name' => 'Done', 'slug' => 'done', 'category' => 'done', 'position' => 5, 'color' => '#10b981', 'is_done' => true],
    ],
];
