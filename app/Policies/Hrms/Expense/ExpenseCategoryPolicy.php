<?php

declare(strict_types=1);

namespace App\Policies\Hrms\Expense;

use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

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
        // Any view scope (own/assigned/all or the legacy slug) or manage: an Employee holding only
        // `view_own` needs the catalogue to file a claim, the same door the claim policy uses.
        return HrmsScope::canRead($user, 'hrms.expenses');
    }
}
