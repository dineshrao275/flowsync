<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HRMS Catalog
    |--------------------------------------------------------------------------
    |
    | The machine-readable catalog for the HRMS module. Every entry is a
    | natural-key row that `TenantProvisioner::provisionHrmsDefaults()`
    | provisions idempotently for **every** tenant — including tenants with
    | HRMS switched off, because the gate is behavioural (middleware), not
    | physical. Enabling a tenant must never require a migration.
    |
    | Mirrors `config/priorities.php`, `config/project_roles.php` and
    | `config/task_statuses.php`. Later phases extend the arrays below; nothing
    | else in the codebase hard-codes these values.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Settings defaults
    |--------------------------------------------------------------------------
    |
    | Written to the single `hrms_settings` row (id = 1) of every tenant. This is
    | operational configuration only — no personal or sensitive data.
    |
    */

    'settings_defaults' => [
        'week_start' => 1,                    // ISO-8601: 1 = Monday
        'timezone' => 'UTC',
        'country' => 'US',
        'region' => null,
        'currency' => 'USD',
        'fiscal_year_start_month' => 1,       // January
        'leave_year_start_month' => 1,        // January
        'attendance' => [
            'workday_hours' => 8,
            'rounding_minutes' => 15,
            'ot_after_minutes' => 480,        // 8h
            'half_day_minutes' => 240,        // 4h
            'full_day_minutes' => 480,
            'allow_negative_ot' => false,
            'auto_derive_from_work_logs' => false,  // opt-in (P20.4)
        ],
        'remote_clock_in' => [
            'enabled' => true,
            'require_ip' => false,
            'allowed_ips' => [],               // empty = no IP restriction
            'require_geofence' => false,
            'max_distance_meters' => 200,
        ],
        'statutory' => [
            'enabled' => false,                // jurisdiction-configured in P10
            'country' => null,
            'currency' => null,
        ],
        'mask_sensitive' => true,
        'data_retention_months' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Employment types
    |--------------------------------------------------------------------------
    */

    'employment_types' => [
        ['name' => 'Full-time', 'code' => 'full_time', 'is_system' => true, 'is_default' => true],
        ['name' => 'Part-time', 'code' => 'part_time', 'is_system' => true, 'is_default' => false],
        ['name' => 'Contractor', 'code' => 'contractor', 'is_system' => true, 'is_default' => false],
        ['name' => 'Intern', 'code' => 'intern', 'is_system' => true, 'is_default' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave types
    |--------------------------------------------------------------------------
    |
    | Seeded for every tenant; each is `is_system` so an admin cannot delete a
    | type that payroll or accruals already reference. `is_paid` and
    | `accrual_period` are consumed by the leave engine (P6).
    |
    */

    'leave_types' => [
        [
            'name' => 'Annual Leave', 'code' => 'annual', 'is_system' => true, 'is_paid' => true,
            'accrual_period' => 'yearly', 'max_balance_days' => 30, 'requires_document' => false,
        ],
        [
            'name' => 'Sick Leave', 'code' => 'sick', 'is_system' => true, 'is_paid' => true,
            'accrual_period' => 'none', 'max_balance_days' => 12, 'requires_document' => true,
        ],
        [
            'name' => 'Unpaid Leave', 'code' => 'unpaid', 'is_system' => true, 'is_paid' => false,
            'accrual_period' => 'none', 'max_balance_days' => null, 'requires_document' => false,
        ],
        [
            'name' => 'Paid Leave', 'code' => 'paid', 'is_system' => true, 'is_paid' => true,
            'accrual_period' => 'none', 'max_balance_days' => null, 'requires_document' => false,
        ],
        [
            'name' => 'Maternity Leave', 'code' => 'maternity', 'is_system' => true, 'is_paid' => true,
            'accrual_period' => 'none', 'max_balance_days' => 182, 'requires_document' => true,
        ],
        [
            'name' => 'Paternity Leave', 'code' => 'paternity', 'is_system' => true, 'is_paid' => true,
            'accrual_period' => 'none', 'max_balance_days' => 14, 'requires_document' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Salary components
    |--------------------------------------------------------------------------
    |
    | `calculation_type` drives the payroll engine (P9): `fixed` is a literal
    | amount, `percentage_of_ctc` is resolved against the annual CTC, and
    | `percentage_of_basic` against the basic component. Employer contributions
    | never reduce take-home pay; they only build gross-up/CTC totals.
    |
    */

    'salary_components' => [
        [
            'name' => 'Basic Salary', 'code' => 'basic', 'is_system' => true, 'calculation_type' => 'fixed',
            'is_taxable' => true, 'is_employer_contribution' => false, 'sequence' => 10,
        ],
        [
            'name' => 'House Rent Allowance', 'code' => 'hra', 'is_system' => true, 'calculation_type' => 'percentage_of_basic',
            'is_taxable' => true, 'is_employer_contribution' => false, 'sequence' => 20,
        ],
        [
            'name' => 'Special Allowance', 'code' => 'special_allowance', 'is_system' => true, 'calculation_type' => 'percentage_of_basic',
            'is_taxable' => true, 'is_employer_contribution' => false, 'sequence' => 30,
        ],
        [
            'name' => 'Provident Fund', 'code' => 'pf_employer', 'is_system' => true, 'calculation_type' => 'percentage_of_basic',
            'is_taxable' => false, 'is_employer_contribution' => true, 'sequence' => 40,
        ],
        [
            'name' => 'Provident Fund (employee)', 'code' => 'pf_employee', 'is_system' => true, 'calculation_type' => 'percentage_of_basic',
            'is_taxable' => false, 'is_employer_contribution' => false, 'sequence' => 50,
        ],
        [
            'name' => 'Professional Tax', 'code' => 'professional_tax', 'is_system' => true, 'calculation_type' => 'fixed',
            'is_taxable' => false, 'is_employer_contribution' => false, 'sequence' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Holidays
    |--------------------------------------------------------------------------
    |
    | Common presets per country, expanded into a `holiday_calendars` +
    | `holidays` pair in P8. A tenant picks one calendar per location; nothing
    | here is mandatory.
    |
    */

    'holidays' => [
        'US' => [
            ['name' => "New Year's Day", 'month' => 1, 'day' => 1, 'is_optional' => false],
            ['name' => 'Independence Day', 'month' => 7, 'day' => 4, 'is_optional' => false],
            ['name' => 'Labor Day', 'month' => 9, 'day' => 1, 'is_optional' => false],
            ['name' => 'Thanksgiving Day', 'month' => 11, 'day' => 28, 'is_optional' => false],
            ['name' => 'Christmas Day', 'month' => 12, 'day' => 25, 'is_optional' => false],
        ],
        'IN' => [
            ['name' => "New Year's Day", 'month' => 1, 'day' => 1, 'is_optional' => false],
            ['name' => 'Republic Day', 'month' => 1, 'day' => 26, 'is_optional' => false],
            ['name' => 'Independence Day', 'month' => 8, 'day' => 15, 'is_optional' => false],
            ['name' => 'Gandhi Jayanti', 'month' => 10, 'day' => 2, 'is_optional' => false],
            ['name' => 'Christmas Day', 'month' => 12, 'day' => 25, 'is_optional' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shift patterns
    |--------------------------------------------------------------------------
    |
    | Consumed by the roster builder in P5. `days` is a 7-slot array of weekday
    | slugs (mon..sun) so a pattern can span weekends.
    |
    */

    'shift_patterns' => [
        [
            'name' => 'General (9-6)', 'code' => 'general', 'is_system' => true,
            'start_time' => '09:00', 'end_time' => '18:00', 'break_minutes' => 60,
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        ],
        [
            'name' => 'Morning (6-3)', 'code' => 'morning', 'is_system' => true,
            'start_time' => '06:00', 'end_time' => '15:00', 'break_minutes' => 45,
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
        ],
        [
            'name' => 'Night (22-7)', 'code' => 'night', 'is_system' => true,
            'start_time' => '22:00', 'end_time' => '07:00', 'break_minutes' => 45,
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Expense categories
    |--------------------------------------------------------------------------
    */

    'expense_categories' => [
        ['name' => 'Travel', 'code' => 'travel', 'is_system' => true, 'requires_receipt' => true],
        ['name' => 'Meals', 'code' => 'meals', 'is_system' => true, 'requires_receipt' => false],
        ['name' => 'Accommodation', 'code' => 'accommodation', 'is_system' => true, 'requires_receipt' => true],
        ['name' => 'Office Supplies', 'code' => 'office_supplies', 'is_system' => true, 'requires_receipt' => false],
        ['name' => 'Software & Subscriptions', 'code' => 'software', 'is_system' => true, 'requires_receipt' => true],
        ['name' => 'Training & Certification', 'code' => 'training', 'is_system' => true, 'requires_receipt' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document types
    |--------------------------------------------------------------------------
    |
    | `is_mandatory` types are counted in the P18 documents-compliance report, and
    | `is_confidential` types additionally require `hrms.documents.view_sensitive`
    | (D2.8). The two statutory slots are empty here and filled in P10.
    |
    */

    'document_types' => [
        ['name' => 'Passport', 'code' => 'passport', 'is_system' => true, 'is_mandatory' => true, 'is_confidential' => true, 'validity_months' => 120],
        ['name' => 'National ID', 'code' => 'national_id', 'is_system' => true, 'is_mandatory' => true, 'is_confidential' => true, 'validity_months' => null],
        ['name' => 'Work Visa', 'code' => 'work_visa', 'is_system' => true, 'is_mandatory' => false, 'is_confidential' => true, 'validity_months' => 36],
        ['name' => 'Employment Contract', 'code' => 'employment_contract', 'is_system' => true, 'is_mandatory' => true, 'is_confidential' => false, 'validity_months' => null],
        ['name' => 'Educational Certificate', 'code' => 'education', 'is_system' => true, 'is_mandatory' => false, 'is_confidential' => false, 'validity_months' => null],
        ['name' => 'Bank Proof', 'code' => 'bank_proof', 'is_system' => true, 'is_mandatory' => true, 'is_confidential' => true, 'validity_months' => null],
        ['name' => 'Experience Letter', 'code' => 'experience_letter', 'is_system' => true, 'is_mandatory' => false, 'is_confidential' => false, 'validity_months' => null],
        ['name' => 'Medical Record', 'code' => 'medical_record', 'is_system' => true, 'is_mandatory' => false, 'is_confidential' => true, 'validity_months' => null],
        ['name' => 'Provident Fund Details', 'code' => 'pf_details', 'is_system' => true, 'is_mandatory' => false, 'is_confidential' => true, 'validity_months' => null],
        ['name' => 'ESI Details', 'code' => 'esi_details', 'is_system' => true, 'is_mandatory' => false, 'is_confidential' => true, 'validity_months' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Statutory configurations
    |--------------------------------------------------------------------------
    |
    | Jurisdiction presets. **Templates only** — a tenant copies the preset for
    | its country into a `statutory_configurations` row it owns, and the payroll
    | engine reads that row, never this file (D2.9). A preset is a starting
    | point a statutory expert must confirm, not a source of truth.
    |
    */

    'statutory_configurations' => [
        'IN' => [
            'country' => 'IN',
            'currency' => 'INR',
            'epf' => [
                'enabled' => true,
                'employee_rate' => 12.0,
                'employer_rate' => 12.0,
                'wage_ceiling' => 15000.00,
                'employee_wage_ceiling' => null,
            ],
            'esi' => [
                'enabled' => true,
                'employee_rate' => 0.75,
                'employer_rate' => 3.25,
                'wage_ceiling' => 21000.00,
                'employee_wage_ceiling' => null,
            ],
            'professional_tax' => [
                'enabled' => true,
                'slabs' => [
                    ['up_to' => 0.00, 'amount' => 0.00],
                    ['up_to' => 4166.67, 'amount' => 0.00],
                    ['up_to' => 8333.33, 'amount' => 41.67],
                    ['up_to' => 12500.00, 'amount' => 83.33],
                    ['up_to' => 16666.67, 'amount' => 125.00],
                    ['up_to' => 20833.33, 'amount' => 166.67],
                    ['up_to' => 25000.00, 'amount' => 208.33],
                    ['up_to' => 41666.67, 'amount' => 250.00],
                    ['up_to' => null, 'amount' => 300.00],
                ],
            ],
            'lwf' => ['enabled' => false, 'months' => []],
            'tds' => ['enabled' => true, 'default_sections' => ['80c', '80d', '24b']],
        ],
        'US' => [
            'country' => 'US',
            'currency' => 'USD',
            'epf' => ['enabled' => false],
            'esi' => ['enabled' => false],
            'professional_tax' => ['enabled' => false, 'slabs' => []],
            'lwf' => ['enabled' => false, 'months' => []],
            'tds' => ['enabled' => false, 'default_sections' => []],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Org starters
    |--------------------------------------------------------------------------
    |
    | Minimal starter rows so a new tenant's org chart is never empty. Deps and
    | designations are seed data a tenant edits; they are `is_system` so they
    | cannot be deleted while referenced.
    |
    */

    'departments' => [
        ['name' => 'Engineering', 'code' => 'engineering', 'is_system' => true, 'sequence' => 10],
        ['name' => 'Sales', 'code' => 'sales', 'is_system' => true, 'sequence' => 20],
        ['name' => 'Support', 'code' => 'support', 'is_system' => true, 'sequence' => 30],
        ['name' => 'Finance', 'code' => 'finance', 'is_system' => true, 'sequence' => 40],
        ['name' => 'People', 'code' => 'people', 'is_system' => true, 'sequence' => 50],
    ],

    'designations' => [
        ['name' => 'Manager', 'code' => 'manager', 'is_system' => true, 'sequence' => 10],
        ['name' => 'Team Lead', 'code' => 'team_lead', 'is_system' => true, 'sequence' => 20],
        ['name' => 'Senior Specialist', 'code' => 'senior_specialist', 'is_system' => true, 'sequence' => 30],
        ['name' => 'Specialist', 'code' => 'specialist', 'is_system' => true, 'sequence' => 40],
    ],

    'locations' => [
        ['name' => 'Head Office', 'code' => 'head_office', 'country' => 'US', 'is_system' => true],
    ],
];
