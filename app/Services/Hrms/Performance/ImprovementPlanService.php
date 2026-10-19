<?php

namespace App\Services\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\ImprovementPlan;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Performance improvement plans. Deliberately simple and human: a reason, a window, a handful of dated
 * milestones the manager ticks off, and an outcome written when it closes. Nothing is scored or computed.
 */
class ImprovementPlanService
{
    private const MAX_MILESTONES = 20;

    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * @param  list<array{title: string, due_date?: string|null}>  $milestones
     *
     * @throws ValidationException
     */
    public function open(Employee $employee, string $reason, Carbon|string $start, Carbon|string $end, array $milestones, User $actor): ImprovementPlan
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();

        if ($to->lte($from)) {
            throw ValidationException::withMessages(['end_date' => __('The plan must end after it starts.')]);
        }
        if (ImprovementPlan::query()->where('employee_id', $employee->id)->where('status', ImprovementPlan::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['form' => __('This person already has an active improvement plan.')]);
        }

        $plan = ImprovementPlan::create([
            'employee_id' => $employee->id,
            'created_by_user_id' => $actor->id,
            'reason' => trim($reason),
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
            'status' => ImprovementPlan::ACTIVE,
            'milestones' => $this->clean($milestones),
        ]);
        $this->audit->log($plan, 'improvement_plan.opened', null, $this->snapshot($plan), $actor);

        return $plan;
    }

    public function toggleMilestone(ImprovementPlan $plan, string $milestoneId, bool $done, User $actor): ImprovementPlan
    {
        $this->requireOpen($plan);

        $found = false;
        $milestones = collect($plan->milestones ?? [])->map(function (array $m) use ($milestoneId, $done, &$found) {
            if ($m['id'] === $milestoneId) {
                $found = true;
                $m['done'] = $done;
            }

            return $m;
        })->all();

        if (! $found) {
            throw ValidationException::withMessages(['milestone' => __('That milestone is not part of this plan.')]);
        }

        $plan->update(['milestones' => $milestones]);
        $this->audit->log($plan, 'improvement_plan.milestone', null, ['milestone' => $milestoneId, 'done' => $done], $actor);

        return $plan->refresh();
    }

    public function extend(ImprovementPlan $plan, Carbon|string $newEnd, User $actor): ImprovementPlan
    {
        $this->requireOpen($plan);
        $to = Carbon::parse($newEnd)->startOfDay();

        if ($to->lte($plan->end_date)) {
            throw ValidationException::withMessages(['end_date' => __('Extend to a date after the current end.')]);
        }

        $before = ['end_date' => $plan->end_date->toDateString()];
        $plan->update(['end_date' => $to->toDateString()]);
        $this->audit->log($plan, 'improvement_plan.extended', $before, ['end_date' => $to->toDateString()], $actor);

        return $plan->refresh();
    }

    public function close(ImprovementPlan $plan, string $status, string $outcome, User $actor): ImprovementPlan
    {
        $this->requireOpen($plan);

        if (! in_array($status, ImprovementPlan::OUTCOMES, true)) {
            throw ValidationException::withMessages(['status' => __('Choose completed, unsuccessful or cancelled.')]);
        }
        if (trim($outcome) === '') {
            throw ValidationException::withMessages(['outcome' => __('Write down the outcome before closing the plan.')]);
        }

        $plan->update(['status' => $status, 'outcome' => trim($outcome), 'closed_at' => now()]);
        $this->audit->log($plan, 'improvement_plan.closed', ['status' => 'active'], $this->snapshot($plan->refresh()), $actor);

        return $plan->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $milestones
     * @return list<array{id: string, title: string, due_date: ?string, done: bool}>
     */
    private function clean(array $milestones): array
    {
        if (count($milestones) > self::MAX_MILESTONES) {
            throw ValidationException::withMessages(['milestones' => __('A plan can have at most :max milestones.', ['max' => self::MAX_MILESTONES])]);
        }

        return collect($milestones)
            ->filter(fn ($m) => trim((string) ($m['title'] ?? '')) !== '')
            ->map(fn ($m) => [
                'id' => (string) Str::uuid(),
                'title' => mb_substr(trim((string) $m['title']), 0, 200),
                'due_date' => ! empty($m['due_date']) ? Carbon::parse($m['due_date'])->toDateString() : null,
                'done' => false,
            ])->values()->all();
    }

    private function requireOpen(ImprovementPlan $plan): void
    {
        if (! $plan->isOpen()) {
            throw ValidationException::withMessages(['form' => __('This plan is already closed.')]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ImprovementPlan $plan): array
    {
        return Auditable::snapshot($plan, ['employee_id', 'status', 'start_date', 'end_date']);
    }
}
