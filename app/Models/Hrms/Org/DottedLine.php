<?php

namespace App\Models\Hrms\Org;

use App\Enums\Hrms\DottedLineKind;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Org/HRMS — a secondary (matrix) reporting line: this person also answers to
 * that one, functionally or for a project. Informational: the primary
 * `employees.manager_id` stays the line approvals and payroll follow.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $manager_id
 * @property DottedLineKind $kind
 * @property string|null $note
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property int|null $created_by
 */
class DottedLine extends Model
{
    protected $table = 'employee_dotted_lines';

    protected $fillable = ['employee_id', 'manager_id', 'kind', 'note', 'effective_from', 'effective_to', 'created_by'];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'manager_id' => 'integer',
            'kind' => DottedLineKind::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
