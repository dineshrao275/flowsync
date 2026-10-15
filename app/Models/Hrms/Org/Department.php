<?php

namespace App\Models\Hrms\Org;

use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Org\DepartmentTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Org/HRMS — a node in the department tree.
 *
 * `parent_id` makes this a tree rather than a list, and the tree is the whole
 * point: payroll scopes, approval routing and the org chart itself are all
 * "this department and everything under it", and a flat list makes each of
 * them a separate recursive query to invent.
 *
 * There is deliberately **no `descendants()` relation here.** A self-relation
 * cannot express arbitrary depth in one SQL statement without a materialized
 * path or a closure table, so the walk is a breadth-first loop over several
 * queries — which is business logic, and D2.16 keeps that out of the model.
 * {@see DepartmentTree::descendantsOf()} owns it, and it
 * is bounded by a `seen` set so a cycle already present in imported data
 * terminates instead of hanging a request. The same reasoning put the
 * employee reporting-line walk in `Employee\ReportingLine`.
 *
 * `employees()` and `designations()` read the `*_id` columns P3.1 added to
 * `employees` and `designations`. Those columns are the *org* context's data —
 * the department axis belongs here even though the rows live on the person and
 * the catalog — so reading them is not reaching into another context's model
 * to bypass its service.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $code
 * @property int|null $parent_id
 * @property int|null $head_employee_id
 * @property string|null $description
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Department|null $parent
 * @property-read Collection<int, Department> $children
 * @property-read Employee|null $head
 * @property-read Collection<int, Designation> $designations
 * @property-read Collection<int, Employee> $employees
 */
class Department extends Model
{
    protected $table = 'departments';

    protected $fillable = [
        'name',
        'slug',
        'code',
        'parent_id',
        'head_employee_id',
        'description',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
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
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    /**
     * The person who runs this department. Null is a valid answer — a
     * department with no head yet is an ordinary state, and a deleted employee
     * leaves their department standing rather than removing it.
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class, 'department_id');
    }

    /** @param  Builder<Department>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<Department>  $query */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * How many people sit in this department *directly* — not in the subtree.
     *
     * The subtree total is a different number and a common bug: a parent
     * department's own headcount is 1 while it "contains" its entire company.
     * {@see DepartmentTree::build()} carries both so the
     * UI never has to guess which one it is looking at.
     */
    public function directHeadcount(): int
    {
        return $this->employees()->count();
    }
}
