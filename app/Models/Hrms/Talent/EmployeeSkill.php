<?php

namespace App\Models\Hrms\Talent;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSkill extends Model
{
    public const SELF = 'self';

    public const ASSESSED = 'assessed';

    protected $table = 'employee_skills';

    protected $fillable = ['employee_id', 'skill_id', 'level', 'source', 'assessed_by_user_id', 'assessed_at', 'note'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'assessed_at' => 'datetime'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
