<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Issue Types Catalog
    |--------------------------------------------------------------------------
    |
    | The default issue types provisioned for every tenant. Runtime customization
    | (rename, recolor, reorder, add, delete) is exposed via `api/issue-types`
    | behind the `workspaces.manage` permission.
    |
    */

    'default_slug' => 'task',

    // hierarchy_level: 0 initiative, 1 epic, 2 standard (task/story/bug), 3 sub-task (P4.1).
    'types' => [
        [
            'name' => 'Initiative',
            'slug' => 'initiative',
            'description' => 'A strategic goal above epics.',
            'icon' => 'flag',
            'color' => '#f59e0b',
            'is_subtask' => false,
            'hierarchy_level' => 0,
            'position' => 0,
        ],
        [
            'name' => 'Task',
            'slug' => 'task',
            'description' => 'A small, distinct piece of work.',
            'icon' => 'check-square',
            'color' => '#3b82f6',
            'is_subtask' => false,
            'position' => 1,
        ],
        [
            'name' => 'Bug',
            'slug' => 'bug',
            'description' => 'A problem or error that impairs product function.',
            'icon' => 'bug',
            'color' => '#ef4444',
            'is_subtask' => false,
            'position' => 2,
        ],
        [
            'name' => 'Story',
            'slug' => 'story',
            'description' => 'A user-facing requirement or functionality.',
            'icon' => 'bookmark',
            'color' => '#10b981',
            'is_subtask' => false,
            'position' => 3,
        ],
        [
            'name' => 'Epic',
            'slug' => 'epic',
            'description' => 'A large body of work composed of multiple tasks/stories.',
            'icon' => 'lightning-bolt',
            'color' => '#8b5cf6',
            'is_subtask' => false,
            'hierarchy_level' => 1,
            'position' => 4,
        ],
        [
            'name' => 'Sub-task',
            'slug' => 'subtask',
            'description' => 'A breakdown of a larger task or issue.',
            'icon' => 'subtask',
            'color' => '#6b7280',
            'is_subtask' => true,
            'hierarchy_level' => 3,
            'position' => 5,
        ],
    ],
];
