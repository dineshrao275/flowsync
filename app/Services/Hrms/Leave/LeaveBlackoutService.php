<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBlackout;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — blackout windows: the catalogue and the ask-time check.
 *
 * `assertAllowed()` is called by request validation: an ask whose range touches
 * an active blackout that covers this person (company-wide, or their department)
 * and this type (or all types) is refused with the window's name, so the
 * employee learns *why* rather than that "something" failed.
 */
class LeaveBlackoutService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return Collection<int, LeaveBlackout> */
    public function all(): Collection
    {
        return LeaveBlackout::query()->with(['type', 'department'])->orderByDesc('from_date')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ?User $actor = null): LeaveBlackout
    {
        $blackout = LeaveBlackout::create($data + ['created_by' => $actor?->id]);
        $this->audit->log($blackout, 'leave.blackout_created', null, $this->snapshot($blackout), $actor);

        return $blackout->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(LeaveBlackout $blackout, array $data, ?User $actor = null): LeaveBlackout
    {
        $before = $this->snapshot($blackout);
        $blackout->update($data);
        $this->audit->log($blackout, 'leave.blackout_updated', $before, $this->snapshot($blackout), $actor);

        return $blackout->refresh();
    }

    public function delete(LeaveBlackout $blackout, ?User $actor = null): void
    {
        $this->audit->log($blackout, 'leave.blackout_deleted', $this->snapshot($blackout), null, $actor);
        $blackout->delete();
    }

    /** @throws ValidationException when the range touches a blackout that applies */
    public function assertAllowed(Employee $employee, LeaveType $type, Carbon $from, Carbon $to): void
    {
        $hit = LeaveBlackout::query()
            ->where('is_active', true)
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->where(fn ($q) => $q->whereNull('leave_type_id')->orWhere('leave_type_id', $type->id))
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $employee->department_id))
            ->orderBy('from_date')
            ->first();

        if ($hit !== null) {
            throw ValidationException::withMessages([
                'from_date' => sprintf(
                    'Leave is blocked during "%s" (%s to %s).',
                    $hit->name,
                    $hit->from_date->toDateString(),
                    $hit->to_date->toDateString(),
                ),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(LeaveBlackout $b): array
    {
        return [
            'name' => $b->name,
            'from_date' => $b->from_date->toDateString(),
            'to_date' => $b->to_date->toDateString(),
            'leave_type_id' => $b->leave_type_id,
            'department_id' => $b->department_id,
            'is_active' => $b->is_active,
        ];
    }
}
