<?php

namespace App\Models\Hrms\Org;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Org/HRMS — what somebody does.
 *
 * Normalizes the free-text `employees.designation` written in P2 (P3.1's
 * migration backfilled the matches it could), and exists because a job title
 * alone cannot be counted on: "which roles are we paying for", "how many
 * engineers do we have" and "does this promotion change the level band" all
 * need a row to point at.
 *
 * `department_id` is nullable on purpose. Most companies have bands that span
 * departments — "Manager" is a level, not a department — and a designation
 * pinned to one department would be invisible to everybody else.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $code
 * @property int|null $level
 * @property int|null $department_id
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Department|null $department
 * @property-read Collection<int, Employee> $employees
 */
class Designation extends Model
{
    protected $table = 'designations';

    protected $fillable = [
        'name',
        'slug',
        'code',
        'level',
        'department_id',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'level' => 'integer',
            'position' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation_id');
    }

    /** @param  Builder<Designation>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
