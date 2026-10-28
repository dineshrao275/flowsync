<?php

declare(strict_types=1);

namespace App\Services\Hrms\Defaults;

use App\Models\Hrms\Employee\EmploymentType;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Org\Location;
use Illuminate\Support\Str;

/**
 * Extracted from HrmsDefaultsProvisioner unchanged; see that class for the insert-only and
 * guard-each-step rules these methods follow.
 */
class OrgDefaults
{
    public function seed(): void
    {
        $this->seedEmploymentTypes();
        $this->seedOrgCatalogs();
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
}
