<?php

/*
| F4.8 — what a new project starts with, by board type. `statuses` are slugs of config/task_statuses.php to
| keep (null = all of them); `issue_types` are the slugs seeded into the project's scheme (null = no scheme,
| every issue type allowed); `sprints` says whether the Sprints tab is offered by default.
*/
return [
    'default' => 'kanban',

    'types' => [
        'scrum' => [
            'label' => 'Scrum',
            'description' => 'Sprints and a backlog; epics, stories, tasks and bugs.',
            'statuses' => ['backlog', 'to-do', 'in-progress', 'in-review', 'done'],
            'issue_types' => ['epic', 'story', 'task', 'bug', 'subtask'],
            'sprints' => true,
        ],
        'kanban' => [
            'label' => 'Kanban',
            'description' => 'A continuous flow board with WIP limits; every issue type.',
            'statuses' => null,
            'issue_types' => null,
            'sprints' => false,
        ],
        'basic' => [
            'label' => 'Basic',
            'description' => 'A simple to-do board: three columns, tasks and sub-tasks.',
            'statuses' => ['to-do', 'in-progress', 'done'],
            'issue_types' => ['task', 'subtask'],
            'sprints' => false,
        ],
    ],
];
