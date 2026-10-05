<?php

namespace App\Models\Hrms\TaskLink;

use App\Enums\Hrms\TaskLinkKind;
use App\Models\Hrms\Employee\Employee;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TaskLink/HRMS — one named bridge between a person and a piece of work.
 *
 * Evidence-only (D2.10): the row lets a reviewer audit a number (which
 * tasks a goal counted, which run a checklist item became), never feed a
 * score. The grain is (employee, task, kind), enforced by a unique index —
 * the same task can evidence a goal and track an onboarding run without
 * the two claims colliding. No `tenant_id`: the tenant database is the
 * isolation boundary (Phase 13).
 */
class TaskLink extends Model
{
    protected $table = 'hrms_task_links';

    protected $fillable = [
        'employee_id',
        'task_id',
        'kind',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TaskLinkKind::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
