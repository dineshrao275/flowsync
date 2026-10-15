<?php

namespace App\Models\Hrms\Expense;

use App\Models\Hrms\Payroll\SalaryComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Expense/HRMS — one claim category in the tenant's catalogue.
 *
 * Seeded starters are `is_system` (renamable, never deletable while
 * claimed against). `requires_receipt_above` of 0 means "always" — a
 * receipt threshold is the tenant's policy, and null means none.
 */
class ExpenseCategory extends Model
{
    protected $table = 'expense_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'requires_receipt_above',
        'is_reimbursable',
        'payroll_component_id',
        'is_active',
        'position',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'requires_receipt_above' => 'decimal:2',
            'is_reimbursable' => 'boolean',
            'payroll_component_id' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    public function payrollComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'payroll_component_id');
    }

    /** @return HasMany<ExpenseClaimItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExpenseClaimItem::class, 'category_id');
    }
}
