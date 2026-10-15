<?php

namespace App\Models\Hrms\Org;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Org/HRMS — a person's seat in one org unit (at most one per unit type).
 *
 * @property int $id
 * @property int $employee_id
 * @property int $org_unit_id
 * @property string $unit_type
 */
class EmployeeOrgUnit extends Model
{
    protected $table = 'employee_org_units';

    protected $fillable = ['employee_id', 'org_unit_id', 'unit_type'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }
}
