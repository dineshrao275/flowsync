<?php

namespace App\Policies\Hrms\Asset;

use App\Models\Hrms\Asset\AssetCategory;
use App\Models\User;

/**
 * Asset/HRMS — who may read or change the register catalogue.
 *
 * The master-data shape exactly: reads take view (or manage), every
 * mutation takes manage alone. Which rows are mutable (never rows with
 * assets behind them) is the service's call, not this policy's.
 */
class AssetCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, AssetCategory $category): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.assets.manage');
    }

    public function update(User $user, AssetCategory $category): bool
    {
        return $user->hasPermission('hrms.assets.manage');
    }

    public function delete(User $user, AssetCategory $category): bool
    {
        return $user->hasPermission('hrms.assets.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.assets.view')
            || $user->hasPermission('hrms.assets.manage');
    }
}
