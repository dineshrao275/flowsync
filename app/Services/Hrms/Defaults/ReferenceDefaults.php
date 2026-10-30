<?php

declare(strict_types=1);

namespace App\Services\Hrms\Defaults;

use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveType;
use App\Services\Hrms\Holiday\HolidayYearSeeder;
use Illuminate\Support\Str;

/**
 * Extracted from HrmsDefaultsProvisioner unchanged; see that class for the insert-only and
 * guard-each-step rules these methods follow.
 */
class ReferenceDefaults
{
    public function seed(): void
    {
        $this->seedDocumentTypes();
        $this->seedLeaveTypes();
        $this->seedHolidayCalendars();
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
