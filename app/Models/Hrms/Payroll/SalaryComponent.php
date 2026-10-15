<?php

namespace App\Models\Hrms\Payroll;

use App\Enums\Hrms\CalculationType;
use App\Enums\Hrms\SalaryComponentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Payroll/HRMS — one pay head in the tenant's catalogue.
 *
 * `calculation_type` tells the engine how the value resolves (P9.2):
 * `fixed` reads the structure row (falling back to `default_value`),
 * percentages multiply against CTC or basic, and `formula` refuses —
 * formulas need an evaluator no phase has built, and a silent zero would
 * pay people wrong. `is_statutory` marks heads the P10 engine owns;
 * hand-editing those fights the computation.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property SalaryComponentType $type
 * @property CalculationType $calculation_type
 */
class SalaryComponent extends Model
{
    protected $table = 'salary_components';

    protected $fillable = [
        'name',
        'slug',
        'code',
        'type',
        'calculation_type',
        'default_value',
        'is_taxable',
        'is_prorated',
        'is_statutory',
        'is_system',
        'is_active',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'type' => SalaryComponentType::class,
            'calculation_type' => CalculationType::class,
            'default_value' => 'decimal:2',
            'is_taxable' => 'boolean',
            'is_prorated' => 'boolean',
            'is_statutory' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsToMany<SalaryStructure, $this> */
    public function structures(): BelongsToMany
    {
        return $this->belongsToMany(SalaryStructure::class, 'salary_structure_components', 'component_id', 'structure_id')
            ->withPivot(['value', 'sequence', 'is_override']);
    }
}
