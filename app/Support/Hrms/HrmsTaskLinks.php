<?php

declare(strict_types=1);

namespace App\Support\Hrms;

use App\Models\Hrms\Performance\GoalTaskLink;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Task;
use App\Support\Integration\BridgeSchema;

/**
 * Whether HR has a claim on a task: it evidences a performance goal, HR linked it to an employee (onboarding,
 * leave, payroll, review ...), or it is an HR-owned ask. Such a task is HR data as much as TMS data, so a token
 * (and the MCP) may read it but never change it. Without the HRMS/TMS bridge nothing can be linked.
 */
final class HrmsTaskLinks
{
    public static function isLinked(Task $task): bool
    {
        if (! BridgeSchema::present()) {
            return false;
        }

        return $task->getAttribute('hrms_employee_id') !== null
            || GoalTaskLink::query()->where('task_id', $task->id)->exists()
            || TaskLink::query()->where('task_id', $task->id)->exists();
    }
}
