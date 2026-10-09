<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveAccrualMethod;
use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — year-end carry-forward and lapse.
 *
 * For a *closed* leave year, every accruing type's positive closing balance is
 * split: up to the cap is carried into the next year (a `carry_forward` row in
 * the new year), the excess — or everything, for a non-carrying type — lapses
 * (a negative `lapse` row in the closed year). Types that never accrue
 * (maternity, unpaid…) are event entitlements, not annual allowances, and are
 * left alone.
 *
 * Idempotent: a `rollover` reference row keyed on the closed year marks a
 * (employee, type) pair as done, so a crashed run is simply re-run. The cap is
 * the lowest of the type's own `carry_forward_cap` and every active policy's
 * `max_carry_forward` covering the type. A dry run computes the same figures and
 * writes nothing.
 */
class LeaveYearRollover
{
    public const REFERENCE = 'rollover';

    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly LeaveCalendar $calendar,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @return array{year: int, to_year: int, employees: int, carried: float, lapsed: float, skipped: int, dry_run: bool}
     *
     * @throws ValidationException when the year has not ended yet
     */
    public function rollover(int $fromYear, ?LeaveType $only = null, bool $dryRun = false, ?User $actor = null): array
    {
        if ($fromYear >= $this->calendar->leaveYearFor(now())) {
            throw ValidationException::withMessages(['year' => "Leave year {$fromYear} has not ended yet."]);
        }

        $result = ['year' => $fromYear, 'to_year' => $fromYear + 1, 'employees' => 0, 'carried' => 0.0, 'lapsed' => 0.0, 'skipped' => 0, 'dry_run' => $dryRun];
        $touched = [];

        $types = LeaveType::query()
            ->where('accrual_method', '!=', LeaveAccrualMethod::None->value)
            ->when($only !== null, fn ($q) => $q->whereKey($only->id))
            ->get();

        foreach ($types as $type) {
            $cap = $this->cap($type);

            $rows = LeaveBalance::query()
                ->where('leave_type_id', $type->id)
                ->where('year', $fromYear)
                ->where('balance', '>', 0)
                ->whereHas('employee', fn ($q) => $q->whereNotIn('status', ['exited', 'terminated']))
                ->with('employee')
                ->get();

            foreach ($rows as $row) {
                if ($this->alreadyRolled($row, $fromYear)) {
                    $result['skipped']++;

                    continue;
                }

                $closing = round((float) $row->balance, 2);
                $carry = $type->carry_forward ? round(min($closing, $cap ?? $closing), 2) : 0.0;
                $lapse = round($closing - $carry, 2);

                $result['carried'] += $carry;
                $result['lapsed'] += $lapse;
                $touched[$row->employee_id] = true;

                if (! $dryRun) {
                    $this->apply($row, $type, $fromYear, $carry, $lapse, $actor);
                }
            }
        }

        $result['employees'] = count($touched);
        $result['carried'] = round($result['carried'], 2);
        $result['lapsed'] = round($result['lapsed'], 2);

        if (! $dryRun && $result['employees'] > 0) {
            $this->audit->log($types->first(), 'leave.rollover', null, $result, $actor);
        }

        return $result;
    }

    private function apply(LeaveBalance $row, LeaveType $type, int $fromYear, float $carry, float $lapse, ?User $actor): void
    {
        DB::transaction(function () use ($row, $type, $fromYear, $carry, $lapse, $actor): void {
            if ($lapse > 0) {
                $this->write($row, $type, $fromYear, LeaveAdjustmentKind::Lapse, -$lapse, $fromYear, "Lapsed at the close of leave year {$fromYear}.", $actor);
                $this->balances->rebuildBalance($row->employee, $type, $fromYear);
            }

            if ($carry > 0) {
                $this->write($row, $type, $fromYear + 1, LeaveAdjustmentKind::CarryForward, $carry, $fromYear, "Carried forward from leave year {$fromYear}.", $actor);
                $this->balances->rebuildBalance($row->employee, $type, $fromYear + 1);
            }
        });
    }

    private function write(LeaveBalance $row, LeaveType $type, int $year, LeaveAdjustmentKind $kind, float $quantity, int $fromYear, string $note, ?User $actor): void
    {
        LeaveAdjustment::create([
            'employee_id' => $row->employee_id,
            'leave_type_id' => $type->id,
            'year' => $year,
            'kind' => $kind->value,
            'quantity' => $quantity,
            'reference_type' => self::REFERENCE,
            'reference_id' => $fromYear,
            'note' => $note,
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function alreadyRolled(LeaveBalance $row, int $fromYear): bool
    {
        return LeaveAdjustment::query()
            ->where('employee_id', $row->employee_id)
            ->where('leave_type_id', $row->leave_type_id)
            ->where('reference_type', self::REFERENCE)
            ->where('reference_id', $fromYear)
            ->exists();
    }

    /** The lowest cap that applies to the type, or null for "no cap". */
    private function cap(LeaveType $type): ?float
    {
        $caps = LeavePolicy::query()
            ->where('is_active', true)
            ->whereNotNull('max_carry_forward')
            ->whereHas('types', fn ($q) => $q->whereKey($type->id))
            ->pluck('max_carry_forward')
            ->map(fn ($v) => (float) $v)
            ->all();

        if ($type->carry_forward_cap !== null) {
            $caps[] = (float) $type->carry_forward_cap;
        }

        return $caps === [] ? null : min($caps);
    }
}
