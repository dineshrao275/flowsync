<?php

/*
| F10.6 — role template gallery. Each template is a list of permission selectors (same syntax as the role
| definitions in config/permissions.php). Applying one creates an ordinary custom role from the slugs that
| exist in the tenant's subscribed products and that the applying admin may grant — nothing here is
| special at runtime, so the admin can edit the copy afterwards.
*/
return [
    'team_lead' => [
        'name' => 'Team lead',
        'description' => 'Runs a team: sees their reports\' work, approves their requests.',
        'permissions' => ['dashboard.view', 'workspaces.view', 'workspaces.create', 'reports.view', 'users.view', 'hrms.view', 'hrms.leave.view_assigned', 'hrms.leave.view_own', 'hrms.leave.approve', 'hrms.attendance.view_assigned', 'hrms.attendance.view_own', 'hrms.expenses.view_assigned', 'hrms.expenses.view_own'],
    ],
    'project_manager' => [
        'name' => 'Project manager',
        'description' => 'Creates workspaces and reads every report; no people data.',
        'permissions' => ['dashboard.view', 'workspaces.view', 'workspaces.create', 'workspaces.manage', 'reports.view', 'users.view', 'webhooks.manage'],
    ],
    'recruiter' => [
        'name' => 'Recruiter / onboarding coordinator',
        'description' => 'Runs onboarding and keeps the employee directory current.',
        'permissions' => ['dashboard.view', 'hrms.view', 'hrms.employees.*', 'hrms.org.view', 'hrms.onboarding.*', 'hrms.documents.view', 'hrms.documents.upload'],
    ],
    'payroll_clerk' => [
        'name' => 'Payroll clerk',
        'description' => 'Prepares payroll runs; cannot change salary structures.',
        'permissions' => ['dashboard.view', 'hrms.view', 'hrms.employees.view', 'hrms.payroll.view', 'hrms.payroll.view_all', 'hrms.payroll.run', 'hrms.expenses.view_all', 'hrms.attendance.view_all'],
    ],
    'support_agent' => [
        'name' => 'Support contact',
        'description' => 'Raises and follows support tickets for the company.',
        'permissions' => ['dashboard.view', 'support.manage', 'users.view'],
    ],
    'read_only' => [
        'name' => 'Read-only observer',
        'description' => 'Can look at dashboards and reports; changes nothing.',
        'permissions' => ['dashboard.view', 'workspaces.view', 'reports.view'],
    ],
];
