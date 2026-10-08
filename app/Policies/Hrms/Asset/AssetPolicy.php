<?php

namespace App\Policies\Hrms\Asset;

use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;

/**
 * Asset/HRMS — who may read, move, or receipt hardware.
 *
 * Reads and registration take view (or manage); every movement — assign,
 * return, maintenance — takes manage alone; acknowledging takes the
 * assignee alone, because a receipt signed by anyone else is not one. The
 * service still refuses wrong-state moves underneath, so the permission
 * opens the door but not the verdict.
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.assets.manage');
    }

    public function assign(User $user, Asset $asset): bool
    {
        return $user->hasPermission('hrms.assets.manage');
    }

    public function returnAsset(User $user, AssetAssignment $assignment): bool
    {
        return $user->hasPermission('hrms.assets.manage')
            || $this->holds($user, $assignment);
    }

    public function maintain(User $user, Asset $asset): bool
    {
        return $user->hasPermission('hrms.assets.manage');
    }

    /**
     * Receipt the open handover: the holder alone. Bound to the assignment
     * (not the asset) because the route names the handover — the asset's
     * pointer follows the row, never leads it. Returns share the holder
     * rule: handing your own hardware back needs no permission, taking
     * anyone else's does.
     */
    public function acknowledgeAssignment(User $user, AssetAssignment $assignment): bool
    {
        return $this->holds($user, $assignment);
    }

    private function holds(User $user, AssetAssignment $assignment): bool
    {
        $employeeId = Employee::where('user_id', $user->id)->value('id');

        return $employeeId !== null && (int) $assignment->employee_id === (int) $employeeId;
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.assets.view')
            || $user->hasPermission('hrms.assets.manage');
    }
}
