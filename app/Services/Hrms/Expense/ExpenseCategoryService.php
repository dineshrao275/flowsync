<?php

namespace App\Services\Hrms\Expense;

use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\User;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Expense/HRMS — the claim catalogue's master data.
 *
 * Split from `ExpenseService` the way compensation split from its catalog:
 * claims move, categories sit still. Seeded rows rename but never delete
 * or repurpose; a category with lines behind it refuses deletion (deactivate
 * instead — history keeps its labels); every mutation audits.
 */
class ExpenseCategoryService
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): ExpenseCategory
    {
        $category = ExpenseCategory::create([
            ...$data,
            'slug' => $this->naming->uniqueSlug(ExpenseCategory::class, (string) $data['name']),
            'is_system' => false,
        ]);

        $this->audit->log($category, 'expense.category_created', null, [
            'slug' => $category->slug,
        ], $actor);

        return $category->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExpenseCategory $category, array $data, ?User $actor = null): ExpenseCategory
    {
        $before = ['name' => $category->name, 'is_active' => $category->is_active];
        $category->update($data);

        $this->audit->log($category->refresh(), 'expense.category_updated', $before, [
            'name' => $category->name,
            'is_active' => $category->is_active,
        ], $actor);

        return $category->refresh();
    }

    /**
     * @throws ValidationException on a system row or one with lines behind it
     */
    public function delete(ExpenseCategory $category, ?User $actor = null): void
    {
        if ($category->is_system) {
            throw ValidationException::withMessages(['form' => 'That is a starter category — rename it, don’t remove it.']);
        }

        if ($category->items()->exists()) {
            throw ValidationException::withMessages(['form' => 'That category labels claim lines — deactivate it instead of deleting history.']);
        }

        $this->audit->log($category, 'expense.category_deleted', [
            'slug' => $category->slug,
        ], null, $actor);

        $category->delete();
    }
}
