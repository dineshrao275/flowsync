<?php

namespace App\Policies\Hrms\Statutory;

use App\Models\Hrms\Statutory\StatutoryConfiguration;
use App\Models\User;

/**
 * Statutory/HRMS — who may read or change a jurisdiction rulebook.
 *
 * One permission answers every method: rates, ceilings and slabs are
 * expert-owned configuration, and there is no self-service read — a
 * rulebook belongs to no employee, so nobody is "their own" reader of one.
 */
class StatutoryConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, StatutoryConfiguration $configuration): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, StatutoryConfiguration $configuration): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, StatutoryConfiguration $configuration): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasPermission('hrms.payroll.statutory.manage');
    }
}
