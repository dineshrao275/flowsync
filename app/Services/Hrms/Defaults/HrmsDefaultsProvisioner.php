<?php

namespace App\Services\Hrms\Defaults;

use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Employee\EmploymentType;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Org\Location;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\Holiday\HolidayYearSeeder;
use Illuminate\Support\Str;

/**
 * Per-tenant HRMS starter data.
 *
 * Extracted from `TenantProvisioner` (P3.5) because that file had reached 368
 * physical lines against the plan's 300-line class ceiling, and the next
 * catalogue to land — `document_types` in P13.2 — would have pushed it further
 * over. The concern is cohesive: everything here is *insert-only starter data
 * that a tenant may then rename*, and nothing here belongs to the provisioning
 * pipeline that surrounds it.
 *
 * Folder-per-context (D2.16.2), matching `Employee/` and `Org/`. The methods are
 * unchanged by the move, which is the point — a refactor commit that also edits
 * behaviour is a refactor nobody can review.
 *
 * Two properties the move must not cost, both of which cost something once:
 *
 *  - **Insert-only, never `updateOrCreate`.** `tenants:provision` runs on every
 *    repair, and an update would reset the tenant's own currency, week start,
 *    statutory configuration, department names and employment-type names back to
 *    the defaults each time. A tenant that renamed "Part-time" keeps its wording.
 *  - **Each step guarded individually, never behind one early return.** A single
 *    "the settings row exists, nothing to do" check short-circuited the whole
 *    method, so a tenant provisioned before a catalogue was added to the list
 *    could never receive it — and `tenants:provision` is the repair path, so it
 *    has to be able to catch such a tenant up.
 *
 * The tables always exist whether or not the HRMS module is switched on; the
 * gate is behavioural (`TenantLimits::isHrmsEnabled()`). No quota checks and no
 * request-scoped dependencies — this runs inside provisioning, before there is a
 * session.
 */
class HrmsDefaultsProvisioner
{
    /**
     * Guarantee the per-tenant HRMS defaults exist: the settings singleton and
     * the employment-type catalog.
     *
     * The `000014` migration already seeds the settings row, so this is the
     * repair path for a database that failed partway, and the guarantee that
     * every tenant has it even when the HRMS module is switched off — the tables
     * always exist; the gate is behavioural (`TenantLimits::isHrmsEnabled()`).
     *
     * Insert-only, never `updateOrCreate`: `tenants:provision` runs on every
     * repair, and an update would reset the tenant's own currency, week start,
     * statutory configuration and employment-type names back to the defaults
     * each time.
     *
     * No quota checks and no request-scoped dependencies — this runs inside
     * provisioning, before there is a session.
     *
     * Each step is guarded **individually**, never behind one early return. A
     * single "the settings row exists, nothing to do" check short-circuited the
     * whole method, which meant a tenant provisioned before employment types
     * were added to this list could never receive them — and `tenants:provision`
     * is the repair path, so it has to be able to catch such a tenant up.
     */
    public function provision(): void
    {
        $this->seedHrmsSettings();
        $this->seedEmploymentTypes();
        $this->seedOrgCatalogs();
        $this->seedDocumentTypes();
        $this->seedLeaveTypes();
        $this->seedHolidayCalendars();
    }

    /**
     * The settings singleton (id = 1).
     *
     * Keyed on the fixed id rather than "any row", so a tenant whose row was
     * deleted is repaired instead of silently keeping a half-provisioned
     * database.
     */
    private function seedHrmsSettings(): void
    {
        if (HrmsSetting::query()->whereKey(HrmsSetting::SINGLETON_ID)->exists()) {
            return;
        }

        $settings = new HrmsSetting;
        // The primary key is a fixed constant, not user input, so it is
        // assigned directly rather than by loosening the model's fillable.
        $settings->id = HrmsSetting::SINGLETON_ID;
        $settings->forceFill(config('hrms.settings_defaults', []));
        $settings->save();
    }

    /**
     * The tenant's contract-type catalog.
     *
     * Keyed on `code` with `firstOrCreate`, so re-provisioning adds a type the
     * catalog later gains and leaves the rest alone: a tenant that renamed
     * "Part-time" keeps its wording, exactly as with the project roles and
     * priorities this mirrors. `is_system` is what stops the row being deleted
     * while employees still point at it.
     */
    private function seedEmploymentTypes(): void
    {
        foreach ((array) config('hrms.employment_types', []) as $index => $type) {
            EmploymentType::query()->firstOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'slug' => Str::slug($type['name']),
                    'is_active' => true,
                    // 10, 20, 30… so a tenant reordering its own types has room
                    // to insert one between two existing rows.
                    'position' => ($index + 1) * 10,
                    'is_system' => (bool) ($type['is_system'] ?? false),
                ]
            );
        }
    }

    /**
     * The org chart, so a new tenant's structure is never empty.
     *
     * A tenant on trial that opens the org page to something blank cannot tell
     * "nobody is assigned yet" from "this feature is broken", and the second
     * reading is the one that generates a support ticket. Three departments and
     * two work sites is the smallest set that is true of any company, so the
     * tenant is deleting rows rather than inventing a structure.
     *
     * Each catalogue is its own guarded step, for the same reason as the two
     * above: a tenant provisioned before P3.5 shipped has to be able to pick them
     * up through `tenants:provision`, which is the repair path.
     */
    private function seedOrgCatalogs(): void
    {
        $this->seedDepartments();
        $this->seedDesignations();
        $this->seedLocations();
    }

    /**
     * The starter departments, keyed on `slug` like every other catalog here.
     *
     * `parent_id` and `head_employee_id` are left null deliberately: a starter
     * reports to nobody and is run by nobody. Guessing a parent would put the
     * tenant's org chart into a shape it never chose, and a guessed head is a
     * person who does not work there — the exact class of invented fact the
     * P2.7 backfill refuses to create for the same reason.
     */
    private function seedDepartments(): void
    {
        foreach ((array) config('hrms.departments', []) as $index => $department) {
            Department::query()->firstOrCreate(
                ['slug' => $department['code']],
                [
                    'name' => $department['name'],
                    'code' => $department['code'],
                    'is_active' => true,
                    'position' => ($index + 1) * 10,
                ]
            );
        }
    }

    /**
     * The starter seniority bands, with the numeric `level` the P3.1 migration
     * added for comp matrices and headcount-by-level charts. Without a level
     * every seeded row leaves the band column null, and a chart that groups by
     * band then reads "no data" for a tenant that has four bands.
     *
     * `department_id` stays null: see `config/hrms.php` — "Manager" is a level,
     * not a department.
     */
    private function seedDesignations(): void
    {
        foreach ((array) config('hrms.designations', []) as $index => $designation) {
            Designation::query()->firstOrCreate(
                ['slug' => $designation['code']],
                [
                    'name' => $designation['name'],
                    'code' => $designation['code'],
                    'level' => $designation['level'] ?? null,
                    'is_active' => true,
                    'position' => ($index + 1) * 10,
                ]
            );
        }
    }

    /**
     * The starter work sites.
     *
     * **Keyed and written on `slug` only, because `locations` has no `code`
     * column** — P3.1 gives that column to departments and designations, and a
     * location is identified by its name anyway. The catalog this reads carried
     * a `'code' => 'head_office'` key that no column has ever accepted; it sat
     * unread and therefore unreported until this method started consuming the
     * array, at which point every tenant's provisioning failed with "table
     * locations has no column named code". `Location::$fillable` and
     * `LocationRequest` never carried `code`, so the divergence was in the
     * catalog alone.
     *
     * No country and no timezone are written either, for the reason in
     * `config/hrms.php`: those are facts about a real place, printed on payslips,
     * and a seeded default would outrank the tenant's own answer.
     */
    private function seedLocations(): void
    {
        foreach ((array) config('hrms.locations', []) as $index => $location) {
            Location::query()->firstOrCreate(
                ['slug' => $location['slug']],
                [
                    'name' => $location['name'],
                    'is_active' => true,
                    'position' => ($index + 1) * 10,
                ]
            );
        }
    }

    /**
     * The starter document catalogue.
     *
     * Keyed on `slug`, because `document_types` has no `code` column; a slug
     * is also the string a compliance report can join against. Insert-only, so
     * a tenant that renames “National ID” keeps its wording while a catalogue
     * that later gains a row still repairs that row onto old tenants.
     */
    private function seedDocumentTypes(): void
    {
        foreach ((array) config('hrms.document_types', []) as $index => $type) {
            DocumentType::query()->firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'category' => $type['category'],
                    'is_mandatory' => (bool) ($type['is_mandatory'] ?? false),
                    'requires_expiry' => (bool) ($type['requires_expiry'] ?? false),
                    'retention_months' => $type['retention_months'] ?? null,
                    'is_sensitive' => (bool) ($type['is_sensitive'] ?? false),
                    'position' => ($index + 1) * 10,
                    'is_active' => true,
                    'is_system' => (bool) ($type['is_system'] ?? false),
                ]
            );
        }
    }

    /**
     * The starter leave catalogue plus the default policy covering it.
     *
     * Keyed on `code`: the catalogue's natural key, and the string payroll
     * joins against. Insert-only, so a tenant that renames "Annual Leave"
     * keeps its wording while a catalogue that later gains a row still
     * repairs that row onto old tenants. Every seeded type is linked to the
     * default policy with `syncWithoutDetaching` — a tenant that unlinked a
     * type keeps it unlinked, and a repaired type joins the policy it was
     * missing from.
     */
    private function seedLeaveTypes(): void
    {
        $policy = LeavePolicy::query()->firstOrCreate(
            ['slug' => 'standard'],
            [
                'name' => 'Standard',
                'accrual_period' => 'annual',
                'start_month' => 1,
                'is_default' => true,
                'is_active' => true,
            ]
        );

        foreach ((array) config('hrms.leave_types', []) as $index => $type) {
            $row = LeaveType::query()->firstOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'slug' => Str::slug($type['name']),
                    'is_paid' => (bool) ($type['is_paid'] ?? true),
                    'accrual_method' => $type['accrual_method'] ?? 'none',
                    'accrual_rate' => $type['accrual_rate'] ?? 0,
                    'max_balance' => $type['max_balance'] ?? null,
                    'carry_forward' => (bool) ($type['carry_forward'] ?? false),
                    'carry_forward_cap' => $type['carry_forward_cap'] ?? null,
                    'encashable' => (bool) ($type['encashable'] ?? false),
                    'requires_document_after_days' => $type['requires_document_after_days'] ?? null,
                    'allow_half_day' => (bool) ($type['allow_half_day'] ?? true),
                    'allow_negative_balance' => (bool) ($type['allow_negative_balance'] ?? false),
                    'color' => $type['color'] ?? null,
                    'position' => ($index + 1) * 10,
                    'is_active' => true,
                    'is_system' => (bool) ($type['is_system'] ?? false),
                ]
            );

            $policy->types()->syncWithoutDetaching([$row->id]);
        }
    }

    /**
     * The starter holiday calendars for the current year.
     *
     * Delegates to the holiday service (the context that owns the
     * expansion), which is idempotent per (calendar, name, date) — a
     * repair run adds a catalogue row an old tenant is missing without
     * duplicating the rows it already has. No per-step early return: the
     * idempotency lives in the service, so this stays a plain call like
     * every other step.
     */
    private function seedHolidayCalendars(): void
    {
        app(HolidayYearSeeder::class)->seedFromConfig(today()->year);
    }
}
