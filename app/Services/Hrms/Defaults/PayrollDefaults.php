<?php

declare(strict_types=1);

namespace App\Services\Hrms\Defaults;

use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\Hrms\Payroll\PayslipTemplate;
use App\Models\Hrms\Payroll\SalaryComponent;
use Illuminate\Support\Str;

/**
 * Extracted from HrmsDefaultsProvisioner unchanged; see that class for the insert-only and
 * guard-each-step rules these methods follow.
 */
class PayrollDefaults
{
    public function seed(): void
    {
        $this->seedSalaryComponents();
        $this->seedPayslipTemplates();
        $this->seedExpenseCategories();
    }

    /**
     * The starter pay heads.
     *
     * Keyed on `code`, insert-only like every other catalogue: a tenant
     * that renames "Basic Salary" keeps its wording, rates and amounts
     * live on the structure rows that use them (the catalogue carries no
     * money, only the rules), and a catalogue that later gains a head
     * repairs it onto old tenants without touching the rest.
     */
    private function seedSalaryComponents(): void
    {
        foreach ((array) config('hrms.salary_components', []) as $index => $component) {
            SalaryComponent::query()->firstOrCreate(
                ['code' => $component['code']],
                [
                    'name' => $component['name'],
                    'slug' => Str::slug($component['name']),
                    'type' => $component['type'],
                    'calculation_type' => $component['calculation_type'] ?? 'fixed',
                    'default_value' => $component['default_value'] ?? 0,
                    'is_taxable' => (bool) ($component['is_taxable'] ?? true),
                    'is_prorated' => (bool) ($component['is_prorated'] ?? true),
                    'is_system' => (bool) ($component['is_system'] ?? false),
                    'is_active' => true,
                    'sequence' => ($index + 1) * 10,
                ]
            );
        }
    }

    /**
     * The tenant's starting payslip layout.
     *
     * One guarded row, keyed on the fixed name: a tenant provisioned before
     * payslips existed gets it on repair, and a tenant that customised or
     * replaced it keeps theirs. Null content renders the built-in layout —
     * the row existing is what the renderer reads, not the content.
     */
    private function seedPayslipTemplates(): void
    {
        PayslipTemplate::query()->firstOrCreate(
            ['name' => 'Standard'],
            [
                'is_default' => true,
                'content' => ['show_employer_contributions' => true],
                'is_active' => true,
            ]
        );
    }

    /**
     * The tenant's expense catalogue.
     *
     * Keyed on `slug` with `firstOrCreate`, insert-only like every other
     * catalogue: a tenant that renamed "Per diem" keeps its wording, and a
     * later catalogue addition still reaches old tenants on repair.
     */
    private function seedExpenseCategories(): void
    {
        foreach ((array) config('hrms.expense_categories', []) as $index => $category) {
            ExpenseCategory::query()->firstOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'requires_receipt_above' => $category['requires_receipt_above'] ?? null,
                    'is_reimbursable' => true,
                    'is_system' => (bool) ($category['is_system'] ?? false),
                    'is_active' => true,
                    'position' => ($index + 1) * 10,
                ]
            );
        }
    }
}
