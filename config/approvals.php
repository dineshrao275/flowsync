<?php

/*
|--------------------------------------------------------------------------
| Approval chains (P2.4 / P2.5)
|--------------------------------------------------------------------------
|
| One entry per approvable domain. Each is the DEFAULT chain, reproducing the
| chain that was hard-coded before approvals v2; a tenant edits its own copy
| in `approval_templates` (seeded from here, insert-only). A domain with no
| row falls back to this file, so behaviour is identical whether or not the
| seed ran.
|
| Step definition keys:
|   type                       manager | department_head | role | user
|   role_slug | permission     for `role`: a role by slug, or the first role holding the permission
|   user_id                    for `user`
|   omit_if_requester_holds    `role` only — drop the step when the requester holds the role
|   stage                      steps sharing a stage number are decided together
|   mode                       sequential (default) | parallel_any | parallel_all — taken from the stage's first step
|   sla_hours                  stage deadline override (else the template's `sla_hours`)
|   when                       { field, op, value } — step applies only when the request payload matches
|
| `fields` lists the payload fields a `when` condition may test for that domain.
*/

return [
    'domains' => [
        'leave' => [
            'label' => 'Leave requests',
            'action' => 'leave.request',
            'fields' => ['days', 'department_id'],
            'steps' => [
                ['type' => 'manager'],
                ['type' => 'department_head'],
                ['type' => 'role', 'role_slug' => 'hr_manager', 'omit_if_requester_holds' => true],
            ],
        ],
        'leave_exemption' => [
            'label' => 'Leave exemption requests',
            'action' => 'leave.exemption',
            'fields' => ['days', 'department_id'],
            'steps' => [
                ['type' => 'manager'],
                ['type' => 'department_head'],
                ['type' => 'role', 'role_slug' => 'hr_manager', 'omit_if_requester_holds' => true],
            ],
        ],
        'expense' => [
            'label' => 'Expense claims',
            'action' => 'expense.decide',
            'fields' => ['amount', 'department_id'],
            'steps' => [
                ['type' => 'manager'],
                ['type' => 'role', 'permission' => 'hrms.expenses.approve'],
            ],
        ],
        'regularization' => [
            'label' => 'Attendance regularization',
            'action' => 'regularize',
            'fields' => ['department_id'],
            'steps' => [['type' => 'manager']],
        ],
        'comp_off' => [
            'label' => 'Comp-off requests',
            'action' => 'comp_off.request',
            'fields' => ['days', 'department_id'],
            'steps' => [['type' => 'manager']],
        ],
        'salary_revision' => [
            'label' => 'Salary revisions',
            'action' => 'compensation.revise',
            'fields' => ['percent', 'department_id'],
            'steps' => [['type' => 'manager']],
        ],
    ],

    // Operators a `when` condition may use.
    'operators' => ['>', '>=', '<', '<=', '=', '!='],

    // Role notified when a step passes its deadline and the template names no escalation role.
    'default_escalation_role' => 'hr_manager',
];
