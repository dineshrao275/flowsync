<?php

namespace Tests\Feature;

use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\Defaults\HrmsDefaultsProvisioner;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.2a — the starter pay heads and the compensation settings section.
 *
 * Provisioning seeds six system components (rules, not money — rates live
 * on the structures that use them) and the revision-letter document type.
 * Insert-only, like every other starter catalogue: a repair run adds what
 * an old tenant is missing without resetting what it renamed.
 */
class HrmsCompensationSeedTest extends TestCase
{
    use IsolatesDatabase;

    public function test_every_tenant_gets_the_starter_components_and_settings(): void
    {
        $components = SalaryComponent::query()->orderBy('sequence')->get();

        $this->assertSame(
            ['Basic Salary', 'House Rent Allowance', 'Special Allowance', 'Provident Fund', 'Provident Fund (employee)', 'Professional Tax'],
            $components->pluck('name')->all(),
        );
        $this->assertTrue($components->every(fn (SalaryComponent $component): bool => $component->is_system));
        $this->assertSame('employer_contribution', $components->firstWhere('code', 'pf_employer')->type->value);
        $this->assertSame('deduction', $components->firstWhere('code', 'professional_tax')->type->value);

        $this->assertSame(10, HrmsSetting::current()->setting('compensation.revision_approval_threshold_percent'));

        $this->assertTrue(DocumentType::query()->where('slug', 'revision_letter')->exists());
    }

    public function test_a_repair_run_adds_missing_rows_without_renaming_anything(): void
    {
        SalaryComponent::query()->where('code', 'basic')->update(['name' => 'Base Pay']);

        app(HrmsDefaultsProvisioner::class)->provision();

        // The tenant's wording survives a repair: insert-only, never
        // updateOrCreate.
        $this->assertSame('Base Pay', SalaryComponent::query()->where('code', 'basic')->value('name'));
        $this->assertSame(6, SalaryComponent::query()->count());
    }
}
