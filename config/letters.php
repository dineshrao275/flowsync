<?php

/*
| F6.2 — starter wording an admin can add with one click and then edit. `{{field}}` placeholders are filled
| from the employee record when a letter is generated; the extras an HR user types at generation time
| (anything alphanumeric) are available too, e.g. {{last_working_day}}.
*/
return [
    'fields' => ['employee_name', 'employee_code', 'designation', 'department', 'joining_date', 'today', 'company'],

    'starters' => [
        'experience-letter' => [
            'name' => 'Experience letter',
            'document_type_slug' => 'experience_letter',
            'body' => "To whom it may concern,\n\nThis is to certify that {{employee_name}} ({{employee_code}}) worked with {{company}} as {{designation}} in the {{department}} department from {{joining_date}} until {{last_working_day}}.\n\nDuring this period we found their conduct and performance satisfactory. We wish them every success.\n\nDate: {{today}}\n\nFor {{company}}",
        ],
        'revision-letter' => [
            'name' => 'Salary revision letter',
            'document_type_slug' => 'revision_letter',
            'body' => "Dear {{employee_name}},\n\nWe are pleased to inform you that your compensation at {{company}} has been revised with effect from {{effective_date}}.\n\nAll other terms of your employment remain unchanged. Thank you for your contribution.\n\nDate: {{today}}\n\nFor {{company}}",
        ],
        'confirmation-letter' => [
            'name' => 'Confirmation letter',
            'document_type_slug' => 'employment_contract',
            'body' => "Dear {{employee_name}},\n\nWe are happy to confirm your employment with {{company}} as {{designation}} with effect from {{effective_date}}, on successful completion of your probation.\n\nDate: {{today}}\n\nFor {{company}}",
        ],
    ],
];
