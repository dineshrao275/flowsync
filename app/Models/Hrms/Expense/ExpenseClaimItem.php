<?php

namespace App\Models\Hrms\Expense;

use App\Models\Hrms\Document\EmployeeDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expense/HRMS — one receipted line on a claim.
 *
 * The amount is the line's own truth: the claim total is the sum of these,
 * recomputed server-side on every write, so a client total is never read.
 * Category and receipt links null on delete — pruning the catalogue or a
 * file orphans the link, never the line.
 */
class ExpenseClaimItem extends Model
{
    protected $table = 'expense_claim_items';

    protected $fillable = [
        'claim_id',
        'category_id',
        'description',
        'amount',
        'spent_at',
        'vendor',
        'receipt_document_id',
        'is_billable',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'claim_id' => 'integer',
            'category_id' => 'integer',
            'amount' => 'decimal:2',
            'spent_at' => 'date',
            'receipt_document_id' => 'integer',
            'is_billable' => 'boolean',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'claim_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'receipt_document_id');
    }
}
