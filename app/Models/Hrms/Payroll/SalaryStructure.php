<?php

namespace App\Models\Hrms\Payroll;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payroll/HRMS — one named CTC template, versioned by effective date.
 *
 * The template names the heads and their per-structure values; the money
 * for a person lives on `EmployeeSalaryStructure`, resolved per pay date.
 * Templates are never edited in place for history that references them —
 * a new effective date versions them instead.
 */
class SalaryStructure extends Model
{
    protected $table = 'salary_structures';

    protected $fillable = [
        'name',
        'slug',
        'currency',
        'effective_from',
        'description',
        'is_default',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsToMany<SalaryComponent, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(SalaryComponent::class, 'salary_structure_components', 'structure_id', 'component_id')
            ->withPivot(['value', 'sequence', 'is_override'])
            ->orderByPivot('sequence');
    }

    /** @return HasMany<EmployeeSalaryStructure, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryStructure::class, 'structure_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
