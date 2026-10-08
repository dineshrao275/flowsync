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
            'regularization_window_days' => 7,      // corrections accepted this far back (P5.4)
        ],
        'remote_clock_in' => [
            'enabled' => true,
            'require_ip' => false,
            'allowed_ips' => [],               // empty = no IP restriction
            'require_geofence' => false,
            'max_distance_meters' => 200,
        ],
        'comp_off' => [
            'from_weekends' => true,            // bank rostered week-offs (P7)
            'from_holidays' => true,            // bank calendar holidays (P8 fills the branch)
            'validity_months' => 3,             // credits expire this far out; null = never
        ],
        'statutory' => [
            'enabled' => false,                // jurisdiction-configured in P10
            'country' => null,
            'currency' => null,
        ],
        'compensation' => [
            // A raise at or above this percent needs approval; below it the
            // revision approves itself (audited as auto, the engine's rule).
            'revision_approval_threshold_percent' => 10,
        ],
        'payroll' => [
            // Overtime multiplier on the derived hourly rate. Straight time
            // by default — a tenant that pays premiums raises it.
            'ot_rate' => 1.0,
        ],
        'performance' => [
            // Peer feedback requests generated per reviewee when a cycle
            // opens manager review. Deterministic, not sampled: the first
            // N eligible peers by id, so re-running names the same names.
            'feedback_peer_count' => 2,
        ],
        'mask_sensitive' => true,
        'data_retention_months' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Employment types
    |--------------------------------------------------------------------------
    |
    | No `is_default` entry: `employment_types` has no such column, and a null
    | `employees.employment_type_id` already means "not set". Nothing infers a
    | type from the absence of one — a backfilled record stays unclassified
    | rather than being guessed to be full-time, because that value feeds
    | payroll.
    |
    */

    'employment_types' => [
        ['name' => 'Full-time', 'code' => 'full_time', 'is_system' => true],
        ['name' => 'Part-time', 'code' => 'part_time', 'is_system' => true],
        ['name' => 'Contractor', 'code' => 'contractor', 'is_system' => true],
        ['name' => 'Intern', 'code' => 'intern', 'is_system' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave types
    |--------------------------------------------------------------------------
    |
    | Seeded for every tenant; each is `is_system` so an admin cannot delete a
    | type that payroll or accruals already reference. Keys map one-to-one
    | onto `leave_types` columns (the HrmsCatalogTest gate enforces both
    | directions), and `accrual_rate` means "days per period of
    | `accrual_method`" — 1.5 monthly, 30 annual. `requires_document_after_days`
    | of 1 means proof is required from the first day.
    |
    */

    'leave_types' => [
        [
            'name' => 'Annual Leave', 'code' => 'annual', 'is_system' => true, 'is_paid' => true,
            'accrual_method' => 'annual', 'accrual_rate' => 30, 'max_balance' => 30,
            'carry_forward' => true, 'carry_forward_cap' => 5, 'encashable' => false,
            'requires_document_after_days' => null, 'allow_half_day' => true,
            'color' => '#059669',
        ],
        [
            'name' => 'Sick Leave', 'code' => 'sick', 'is_system' => true, 'is_paid' => true,
            'accrual_method' => 'monthly', 'accrual_rate' => 1, 'max_balance' => 12,
            'carry_forward' => false, 'encashable' => false,
            'requires_document_after_days' => 1, 'allow_half_day' => true,
            'color' => '#d97706',
        ],
        [
            'name' => 'Unpaid Leave', 'code' => 'unpaid', 'is_system' => true, 'is_paid' => false,
            'accrual_method' => 'none', 'allow_negative_balance' => true,
            'requires_document_after_days' => null, 'allow_half_day' => true,
            'color' => '#6b7280',
        ],
        [
            'name' => 'Maternity Leave', 'code' => 'maternity', 'is_system' => true, 'is_paid' => true,
            'accrual_method' => 'none', 'max_balance' => 182,
            'requires_document_after_days' => 1, 'allow_half_day' => false,
            'color' => '#7c3aed',
        ],
        [
            'name' => 'Paternity Leave', 'code' => 'paternity', 'is_system' => true, 'is_paid' => true,
            'accrual_method' => 'none', 'max_balance' => 14,
            'requires_document_after_days' => 1, 'allow_half_day' => false,
            'color' => '#0284cb',
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
    | Keys map one-to-one onto `salary_components` columns (the
    | HrmsCatalogTest gate enforces both directions): `type` names the pay
    | head family, and rates/amounts live on the structure rows that use
    | them — the catalogue carries no money, only the rules.
    |
    */

    'salary_components' => [
        [
            'name' => 'Basic Salary', 'code' => 'basic', 'is_system' => true, 'type' => 'earning',
            'calculation_type' => 'fixed', 'is_taxable' => true, 'sequence' => 10,
        ],
        [
            'name' => 'House Rent Allowance', 'code' => 'hra', 'is_system' => true, 'type' => 'earning',
            'calculation_type' => 'percentage_of_basic', 'is_taxable' => true, 'sequence' => 20,
        ],
        [
            'name' => 'Special Allowance', 'code' => 'special_allowance', 'is_system' => true, 'type' => 'earning',
            'calculation_type' => 'percentage_of_basic', 'is_taxable' => true, 'sequence' => 30,
        ],
        [
            'name' => 'Provident Fund', 'code' => 'pf_employer', 'is_system' => true, 'type' => 'employer_contribution',
            'calculation_type' => 'percentage_of_basic', 'is_taxable' => false, 'sequence' => 40,
        ],
        [
            'name' => 'Provident Fund (employee)', 'code' => 'pf_employee', 'is_system' => true, 'type' => 'deduction',
            'calculation_type' => 'percentage_of_basic', 'is_taxable' => false, 'sequence' => 50,
        ],
        [
            'name' => 'Professional Tax', 'code' => 'professional_tax', 'is_system' => true, 'type' => 'deduction',
            'calculation_type' => 'fixed', 'is_taxable' => false, 'sequence' => 60,
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
    |
    | Seeded for every tenant (P11.1); keys map one-to-one onto
    | `expense_categories` columns (the HrmsCatalogTest gate enforces both
    | directions). `requires_receipt_above` of 0 means "always" — a receipt
    | starters that demand proof say 0 and the rest say null.
    |
    */

    'expense_categories' => [
        ['name' => 'Travel', 'slug' => 'travel', 'is_system' => true, 'requires_receipt_above' => 0],
        ['name' => 'Meals', 'slug' => 'meals', 'is_system' => true, 'requires_receipt_above' => null],
        ['name' => 'Accommodation', 'slug' => 'accommodation', 'is_system' => true, 'requires_receipt_above' => 0],
        ['name' => 'Office Supplies', 'slug' => 'office_supplies', 'is_system' => true, 'requires_receipt_above' => null],
        ['name' => 'Software & Subscriptions', 'slug' => 'software', 'is_system' => true, 'requires_receipt_above' => 0],
        ['name' => 'Training & Certification', 'slug' => 'training', 'is_system' => true, 'requires_receipt_above' => 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document types
    |--------------------------------------------------------------------------
    |
    | Seeded for every tenant (P13.2). **`category` is required, not optional**:
    | it is the family a compliance report and the P18 completeness check group
    | by, and a document with no family is a document no report can find.
    |
    | **Keyed on `slug`; `document_types` has no `code` column.** An earlier
    | revision of this file wrote `'code' => 'passport'`, `'is_confidential'` and
    | `'validity_months'`, none of which is a column — the same defect the
    | `locations` starters had. `is_sensitive` is the real column name, and
    | `validity_months` is not a column at all *and* not the same idea as
    | `retention_months`: how long a passport stays valid is a fact about one
    | document (stored per row in `employee_documents.expires_at`), while
    | retention is how long the tenant keeps the bytes. A type-level validity
    | figure would be a guess about somebody's passport.
    |
    | **`retention_months` is left null on every row**, which means "keep
    | indefinitely". Retention is a jurisdictional question and the statutory
    | phase (P10) is where a jurisdiction gets a say; inventing a number here
    | would put a five-year promise in a tenant's compliance report that nobody
    | ever agreed to.
    |
    | **`is_mandatory` is the four a report would demand of anybody.** PF and
    | ESI details are deliberately *not* mandatory: they are India-specific
    | obligations, and a starter catalogue that hard-codes them asserts
    | something false about a tenant in another country.
    |
    | **`is_sensitive` is the D2.8 permission, under a different column name.**
    | D2.8 gates confidential documents behind `hrms.documents.view_sensitive`
    | and writes an `hrms_data_access_logs` row on every read; the earlier
    | `is_confidential` key said the same thing, and the migration settled on
    | `is_sensitive` for the type and `confidential` for the row. A type-level
    | flag is the *default* sensitivity of the thing, and a row can still be
    | marked confidential on top of it — the two are not the same question, and
    | the service checks both.
    |
    */

    'document_types' => [
        ['name' => 'Passport', 'slug' => 'passport', 'category' => 'identity', 'is_mandatory' => true, 'is_sensitive' => true, 'requires_expiry' => true, 'is_system' => true],
        ['name' => 'National ID', 'slug' => 'national_id', 'category' => 'identity', 'is_mandatory' => true, 'is_sensitive' => true, 'requires_expiry' => true, 'is_system' => true],
        ['name' => 'Work Visa', 'slug' => 'work_visa', 'category' => 'identity', 'is_mandatory' => false, 'is_sensitive' => true, 'requires_expiry' => true, 'is_system' => true],
        ['name' => 'Employment Contract', 'slug' => 'employment_contract', 'category' => 'employment', 'is_mandatory' => true, 'is_sensitive' => false, 'requires_expiry' => false, 'is_system' => true],
        ['name' => 'Educational Certificate', 'slug' => 'education', 'category' => 'education', 'is_mandatory' => false, 'is_sensitive' => false, 'requires_expiry' => false, 'is_system' => true],
        ['name' => 'Bank Proof', 'slug' => 'bank_proof', 'category' => 'bank', 'is_mandatory' => true, 'is_sensitive' => true, 'requires_expiry' => false, 'is_system' => true],
        ['name' => 'Medical Record', 'slug' => 'medical_record', 'category' => 'medical', 'is_mandatory' => false, 'is_sensitive' => true, 'requires_expiry' => false, 'is_system' => true],
        ['name' => 'Experience Letter', 'slug' => 'experience_letter', 'category' => 'letter', 'is_mandatory' => false, 'is_sensitive' => false, 'requires_expiry' => false, 'is_system' => true],
        ['name' => 'Revision Letter', 'slug' => 'revision_letter', 'category' => 'letter', 'is_mandatory' => false, 'is_sensitive' => false, 'requires_expiry' => false, 'is_system' => true],
        ['name' => 'Provident Fund Details', 'slug' => 'pf_details', 'category' => 'tax', 'is_mandatory' => false, 'is_sensitive' => true, 'requires_expiry' => false, 'is_system' => true],
        ['name' => 'ESI Details', 'slug' => 'esi_details', 'category' => 'tax', 'is_mandatory' => false, 'is_sensitive' => true, 'requires_expiry' => false, 'is_system' => true],
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
    | Minimal starter rows so a new tenant's org chart is never empty. Seeded
    | by `TenantProvisioner::provisionHrmsDefaults()` for every tenant, and
    | deliberately the smallest set that is true of any company: three
    | departments and two work sites. A tenant that finds this list wrong
    | renames it — seeding must not guess a structure (D2.16).
    |
    | **These rows have no `is_system` column and must not grow one.** The org
    | tables (P3.1) carry `is_active`, and the retirement path is deactivation.
    | What keeps a referenced row from being destroyed is P3.2's service refusing
    | a hard delete of a department with children or employees and suggesting
    | deactivation instead — a *rule*, not a flag. A `is_system` boolean would be
    | a second, weaker answer to the same question, and the two would disagree:
    | the flag would forbid deleting an empty "Engineering" (harmless) while
    | staying silent about the empty "Engineering" a tenant created themselves.
    |
    | **No `position` key either** — the column is `position` and the seeder
    | derives it from the array index as 10/20/30, so a tenant reordering its
    | own rows has room to insert one between two existing ones. This mirrors
    | `employment_types` above.
    |
    | **A starter location is keyed on `slug`, not `code`,** because `locations`
    | is the one org table with no `code` column (P3.1 gives the column to
    | departments and designations only). An earlier revision of this file wrote
    | `'code' => 'head_office'` for the site, which was harmless *only* because
    | nothing read the array until P3.5 — the seeder then failed with "table
    | locations has no column named code". `Location::$fillable` and
    | `LocationRequest` never carried `code` either, so the HTTP surface was
    | right and only the catalog lied. The natural key of a table is the column
    | the table has.
    |
    | **No country on a starter location.** `settings_defaults.country` is a
    | fallback for a tenant that has not chosen, and a site's country is printed
    | on a payslip's tax declaration: guessing "US" writes a wrong fact about an
    | office in another country, which is worse than the empty field. `null` is
    | also what lets the P5 attendance geofence and the P9 statutory engine read
    | one source of truth.
    |
    */

    'departments' => [
        ['name' => 'Engineering', 'code' => 'engineering'],
        ['name' => 'Sales', 'code' => 'sales'],
        ['name' => 'Operations', 'code' => 'operations'],
    ],

    /*
    | Generic seniority bands, not job titles. "Senior" is already in the name of
    | a title ("Senior Engineer"); what a comp matrix, a promotion review and a
    | headcount-by-level chart need to sort on is the *band*, which is why
    | `designations.level` is numeric and set here. `department_id` stays null on
    | purpose: "Manager" is a level, not a department, and a band that belonged to
    | one department would be invisible to everybody outside it.
    */

    'designations' => [
        ['name' => 'Manager', 'code' => 'manager', 'level' => 4],
        ['name' => 'Team Lead', 'code' => 'team_lead', 'level' => 3],
        ['name' => 'Senior Specialist', 'code' => 'senior_specialist', 'level' => 2],
        ['name' => 'Specialist', 'code' => 'specialist', 'level' => 1],
    ],

    'locations' => [
        ['name' => 'Headquarters', 'slug' => 'headquarters'],
        ['name' => 'Remote', 'slug' => 'remote'],
    ],
];
