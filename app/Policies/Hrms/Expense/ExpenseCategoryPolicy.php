<?php

namespace App\Policies\Hrms\Expense;

use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\User;

/**
 * Expense/HRMS — who may read or change the claim catalogue.
 *
 * The master-data shape exactly: reads take view (or manage), every
 * mutation takes manage alone. Which rows are mutable (never starters,
 * never rows with lines behind them) is the service's call, not this
 * policy's — mutability is lifecycle, not identity.
 */
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.expenses.manage');
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $user->hasPermission('hrms.expenses.manage');
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $user->hasPermission('hrms.expenses.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.expenses.view')
            || $user->hasPermission('hrms.expenses.manage');
    }
}
