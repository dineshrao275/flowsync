<?php

declare(strict_types=1);

namespace App\Services\Hrms\Expense;

use App\Enums\Hrms\ExpenseClaimStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\Hrms\Expense\ExpenseClaimItem;
use App\Support\Hrms\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Per-line rules for an expense claim: category and amount checks, receipt thresholds and the
 * category limits, plus the total. Extracted from ExpenseService unchanged.
 */
class ExpenseItemRules
{
    /**
     * One line per item, validated: an active catalogue row (or none — an
     * ad-hoc line names no head), a positive amount, and a receipt where
     * the category demands one. Receipts must belong to the employee, like
     * declaration proofs: evidence for somebody else's money is refused.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException on a bad line
     */
    public function validatedItems(Employee $employee, array $items): array
    {
        $lines = [];

        foreach (array_values($items) as $index => $item) {
            $category = ! empty($item['category_id'])
                ? ExpenseCategory::find($item['category_id'])
                : null;

            if (! empty($item['category_id']) && $category === null) {
                throw ValidationException::withMessages(["items.{$index}.category_id" => __('That category does not exist.')]);
            }

            if ($category !== null && ! $category->is_active) {
                throw ValidationException::withMessages(["items.{$index}.category_id" => __('That category is retired.')]);
            }

            $amount = Money::fromDecimal((string) ($item['amount'] ?? '0'));

            if ($amount->isNegative() || $amount->isZero()) {
                throw ValidationException::withMessages(["items.{$index}.amount" => __('A line prices positive money.')]);
            }

            $receiptId = $item['receipt_document_id'] ?? null;

            if ($receiptId !== null) {
                $receipt = EmployeeDocument::find($receiptId);

                if ($receipt === null || (int) $receipt->employee_id !== (int) $employee->id) {
                    throw ValidationException::withMessages(["items.{$index}.receipt_document_id" => __('That file does not belong to this employee.')]);
                }
            }

            $threshold = $category?->requires_receipt_above;

            if ($threshold !== null && $amount->minor > Money::fromDecimal((string) $threshold)->minor && $receiptId === null) {
                throw ValidationException::withMessages(["items.{$index}.receipt_document_id" => __('Lines over :threshold need a receipt.', ['threshold' => $threshold])]);
            }

            $spentAt = isset($item['spent_at']) ? Carbon::parse((string) $item['spent_at']) : now();
            $this->assertWithinLimits($employee, $category, $amount, $spentAt, $lines, $index);

            $lines[] = [
                'category_id' => $category?->id,
                'description' => (string) ($item['description'] ?? ''),
                'amount' => $amount->toDecimal(),
                'spent_at' => isset($item['spent_at']) ? Carbon::parse((string) $item['spent_at'])->toDateString() : null,
                'vendor' => $item['vendor'] ?? null,
                'receipt_document_id' => $receiptId,
                'is_billable' => (bool) ($item['is_billable'] ?? false),
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * Category ceilings: one line may not exceed `max_per_line`, and an employee's lines in the category for
     * the month (counting claims already filed and the lines of this very request) may not exceed `monthly_limit`.
     *
     * @param  list<array<string, mixed>>  $earlier  lines of this request validated so far
     */
    private function assertWithinLimits(Employee $employee, ?ExpenseCategory $category, Money $amount, Carbon $spentAt, array $earlier, int $index): void
    {
        if ($category === null) {
            return;
        }

        if ($category->max_per_line !== null && $amount->minor > Money::fromDecimal((string) $category->max_per_line)->minor) {
            throw ValidationException::withMessages(["items.{$index}.amount" => __('This category allows at most :limit per line.', ['limit' => $category->max_per_line])]);
        }

        if ($category->monthly_limit === null) {
            return;
        }

        $month = [$spentAt->copy()->startOfMonth()->toDateString(), $spentAt->copy()->endOfMonth()->toDateString()];
        $used = Money::fromDecimal((string) ExpenseClaimItem::query()
            ->where('category_id', $category->id)
            ->whereBetween('spent_at', $month)
            ->whereHas('claim', fn ($q) => $q->where('employee_id', $employee->id)
                ->whereIn('status', [ExpenseClaimStatus::Submitted, ExpenseClaimStatus::Pending, ExpenseClaimStatus::Approved, ExpenseClaimStatus::Paid]))
            ->sum('amount') ?: '0');

        $minor = $used->minor + $amount->minor;
        foreach ($earlier as $line) {
            if ((int) $line['category_id'] === $category->id && $line['spent_at'] !== null && $line['spent_at'] >= $month[0] && $line['spent_at'] <= $month[1]) {
                $minor += Money::fromDecimal((string) $line['amount'])->minor;
            }
        }

        if ($minor > Money::fromDecimal((string) $category->monthly_limit)->minor) {
            throw ValidationException::withMessages(["items.{$index}.amount" => __('This would take you over the :limit monthly limit for this category.', ['limit' => $category->monthly_limit])]);
        }
    }

    public function sum(array $lines): string
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            $total = $total->add(Money::fromDecimal((string) $line['amount']));
        }

        return $total->toDecimal();
    }
}
