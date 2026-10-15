<?php

namespace App\Models\Hrms\Org;

use App\Enums\Hrms\OrgUnitType;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Org/HRMS — a business unit, legal entity or cost centre.
 *
 * Typed nodes in their own small trees (`parent_id` stays within the type),
 * separate from the department tree. `meta` carries type-specific facts (a
 * legal entity's registration number and country, a cost centre's budget code).
 *
 * @property int $id
 * @property OrgUnitType $type
 * @property string $name
 * @property string $code
 * @property int|null $parent_id
 * @property int|null $head_employee_id
 * @property string|null $description
 * @property array<string, mixed>|null $meta
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrgUnit extends Model
{
    protected $table = 'org_units';

    protected $fillable = ['type', 'name', 'code', 'parent_id', 'head_employee_id', 'description', 'meta', 'is_active', 'position'];

    protected function casts(): array
    {
        return [
            'type' => OrgUnitType::class,
            'parent_id' => 'integer',
            'head_employee_id' => 'integer',
            'meta' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeOrgUnit::class, 'org_unit_id');
    }
}
