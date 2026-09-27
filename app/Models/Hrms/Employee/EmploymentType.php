<?php

namespace App\Models\Hrms\Employee;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Employee/HRMS — a per-tenant employment contract type.
 *
 * A table and not an enum on `employees` because the set belongs to the tenant:
 * "Contractor" and "Intern" are real employment types in some companies and
 * meaningless in others. `is_system` marks the rows the seeder installs, which
 * a tenant may rename and reorder but not delete — an employee pointing at a
 * deleted type would silently lose their contract classification.
 *
 * Deactivation (`is_active`) is the supported way to retire a type: historical
 * employees keep the classification they were hired under while nobody new can
 * be given it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $code
 * @property bool $is_active
 * @property int $position
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Employee> $employees
 */
class EmploymentType extends Model
{
    protected $table = 'employment_types';

    protected $fillable = [
        'name',
        'slug',
        'code',
        'is_active',
        'position',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @param  Builder<EmploymentType>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<EmploymentType>  $query */
    public function scopeSystem(Builder $query): void
    {
        $query->where('is_system', true);
    }
}
