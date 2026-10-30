<?php

declare(strict_types=1);

namespace App\Services\Hrms\Shift;

use App\Enums\Hrms\ApprovalStatus;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\ShiftSwap;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\Hrms\Shared\ValueObjects\ApproverSpec;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift/HRMS — two people trade their shifts for one day.
 *
 * The counterparty must agree first, then the requester's manager (when there is one). The shifts each
 * person holds on that day are snapshotted at request time and re-checked on the final approval, so a
 * roster edited in between cannot be silently overwritten. Applying writes two single-day roster rows
 * through RosterService, which splits whatever longer roster covered the day.
 */
class ShiftSwapService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ReportingLine $reporting,
        private readonly RosterService $rosters,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /** The shift a person works on a day: the roster row covering it, else their default shift (null = day off). */
    public function shiftOn(Employee $employee, Carbon $date): ?int
    {
        $roster = AttendanceRoster::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('effective_from')
            ->first();

        if ($roster !== null) {
            $weeklyOff = (int) (($roster->weekly_offs ?? [])[$date->dayOfWeekIso - 1] ?? 0) === 1;

            return $weeklyOff ? null : $roster->shift_id;
        }

        return $employee->getAttribute('shift_id');
    }

    /** @throws ValidationException */
    public function request(Employee $requester, Employee $counterparty, Carbon|string $date, ?string $reason, User $actor): ShiftSwap
    {
        $day = Carbon::parse($date)->startOfDay();

        if ($requester->id === $counterparty->id) {
            throw ValidationException::withMessages(['counterparty_employee_id' => __('Choose someone else to swap with.')]);
        }
        if ($day->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['swap_date' => __('A swap has to be for today or a later day.')]);
        }
        if ($counterparty->user_id === null) {
            throw ValidationException::withMessages(['counterparty_employee_id' => __('That person has no login to agree to the swap.')]);
        }

        $mine = $this->shiftOn($requester, $day);
        $theirs = $this->shiftOn($counterparty, $day);
        if ($mine === $theirs) {
            throw ValidationException::withMessages(['form' => __('You both work the same shift that day, so there is nothing to swap.')]);
        }

        $open = ShiftSwap::query()->where('status', ShiftSwap::PENDING)->whereDate('swap_date', $day->toDateString())
            ->where(fn ($q) => $q->whereIn('requester_employee_id', [$requester->id, $counterparty->id])->orWhereIn('counterparty_employee_id', [$requester->id, $counterparty->id]))
            ->exists();
        if ($open) {
            throw ValidationException::withMessages(['form' => __('One of you already has a swap waiting for that day.')]);
        }

        return DB::transaction(function () use ($requester, $counterparty, $day, $mine, $theirs, $reason, $actor): ShiftSwap {
            $swap = ShiftSwap::create([
                'requester_employee_id' => $requester->id,
                'counterparty_employee_id' => $counterparty->id,
                'swap_date' => $day->toDateString(),
                'requester_shift_id' => $mine,
                'counterparty_shift_id' => $theirs,
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
                'status' => ShiftSwap::PENDING,
                'requested_by_user_id' => $actor->id,
            ]);

            $steps = [ApproverSpec::user($counterparty->user_id, $counterparty->id)];
            $manager = $this->reporting->managerOf($requester);
            if ($manager?->user_id !== null && $manager->user_id !== $counterparty->user_id && $manager->user_id !== $actor->id) {
                $steps[] = ApproverSpec::user($manager->user_id, $manager->id);
            }

            $approval = $this->approvals->request(
                $steps, $swap, 'shift.swap', 'Shift swap',
                ['swap_date' => $day->toDateString()], $actor, $requester->id,
            );
            $swap->update(['approval_id' => $approval->id]);
            $this->audit->log($swap, 'shift_swap.requested', null, $this->snapshot($swap->refresh()), $actor);

            return $approval->status === ApprovalStatus::Approved ? $this->apply($swap, $actor) : $swap->refresh();
        });
    }

    public function approve(ShiftSwap $swap, User $actor, ?string $note = null): ShiftSwap
    {
        $this->requireOpen($swap);
        $approval = $this->approvals->approve($swap->approval, $actor, $note);

        return $approval->status === ApprovalStatus::Approved ? $this->apply($swap, $actor, $note) : $swap->refresh();
    }

    public function reject(ShiftSwap $swap, User $actor, string $note): ShiftSwap
    {
        $this->requireOpen($swap);
        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => __('A rejection needs a reason the requester can act on.')]);
        }

        $this->approvals->reject($swap->approval, $actor, $note);
        $swap->update(['status' => ShiftSwap::REJECTED, 'decided_at' => now(), 'decision_note' => mb_substr(trim($note), 0, 500)]);
        $this->audit->log($swap, 'shift_swap.rejected', ['status' => 'pending'], $this->snapshot($swap->refresh()), $actor);

        return $swap->refresh();
    }

    public function withdraw(ShiftSwap $swap, User $actor): ShiftSwap
    {
        $this->requireOpen($swap);
        $this->approvals->cancel($swap->approval, $actor);
        $swap->update(['status' => ShiftSwap::WITHDRAWN, 'decided_at' => now()]);
        $this->audit->log($swap, 'shift_swap.withdrawn', ['status' => 'pending'], $this->snapshot($swap->refresh()), $actor);

        return $swap->refresh();
    }

    private function apply(ShiftSwap $swap, ?User $actor, ?string $note = null): ShiftSwap
    {
        return DB::transaction(function () use ($swap, $actor, $note): ShiftSwap {
            $day = Carbon::parse($swap->swap_date)->startOfDay();
            $a = $swap->requester;
            $b = $swap->counterparty;

            // The rosters moved since the request: refuse rather than overwrite someone's newer schedule.
            if ($this->shiftOn($a, $day) !== $swap->requester_shift_id || $this->shiftOn($b, $day) !== $swap->counterparty_shift_id) {
                $swap->update(['status' => ShiftSwap::REJECTED, 'decided_at' => now(), 'decision_note' => __('The roster changed after the swap was requested.')]);

                throw ValidationException::withMessages(['form' => __('The roster changed after the swap was requested, so it was not applied.')]);
            }

            $this->put($a, $swap->counterparty_shift_id, $day, $actor);
            $this->put($b, $swap->requester_shift_id, $day, $actor);

            $swap->update([
                'status' => ShiftSwap::APPLIED, 'decided_at' => now(), 'applied_at' => now(),
                'decision_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
            ]);
            $this->audit->log($swap, 'shift_swap.applied', ['status' => 'pending'], $this->snapshot($swap->refresh()), $actor);

            return $swap->refresh();
        });
    }

    private function put(Employee $employee, ?int $shiftId, Carbon $day, ?User $actor): void
    {
        $this->rosters->assign($employee, $shiftId, $day, $day, array_fill(0, 7, $shiftId === null ? 1 : 0), false, $actor);
    }

    private function requireOpen(ShiftSwap $swap): void
    {
        if (! $swap->isOpen() || $swap->approval === null) {
            throw ValidationException::withMessages(['form' => __('This swap has already been decided.')]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ShiftSwap $swap): array
    {
        return Auditable::snapshot($swap, ['requester_employee_id', 'counterparty_employee_id', 'swap_date', 'status', 'approval_id']);
    }
}
