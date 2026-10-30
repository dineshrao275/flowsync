<?php

declare(strict_types=1);

namespace App\Services\Hrms\Defaults;

use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\Approval\ApprovalTemplateSeeder;
use App\Services\Hrms\Shift\ShiftPatternSeeder;

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
        app(OrgDefaults::class)->seed();
        app(ReferenceDefaults::class)->seed();
        app(PayrollDefaults::class)->seed();
        app(ShiftPatternSeeder::class)->seed();
        app(ApprovalTemplateSeeder::class)->seed();
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
}
