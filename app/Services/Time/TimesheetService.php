<?php

declare(strict_types=1);

namespace App\Services\Time;

use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Weekly timesheets over work logs.
 *
 * A week is Monday 00:00 → next Monday 00:00 in the app timezone. Submitting freezes the owner's
 * logs for that week (`assertOpen` is called by the work-log writes) and snapshots the totals;
 * approving keeps the freeze, rejecting lifts it so the owner can fix and resubmit. Deciders are
 * tenant admins and the lead of a project the sheet has time on — never the owner themself.
 */
class TimesheetService
{
    public static function weekStart(Carbon|string $date): Carbon
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /**
     * Whether `$userId` may add/change/remove a log that starts at `$at`.
     *
     * @throws ValidationException when that week's timesheet is submitted or approved
     */
    public function assertOpen(int $userId, Carbon $at): void
    {
        if (! Schema::hasTable('timesheets')) {
            return;
        }

        $sheet = Timesheet::query()->where('user_id', $userId)->whereDate('week_start', self::weekStart($at)->toDateString())->first();

        if ($sheet?->locksLogs()) {
            throw ValidationException::withMessages([
                'form' => __('That week\'s timesheet is :status, so its time cannot be changed. Ask an approver to reject it first.', ['status' => __($sheet->status)]),
            ]);
        }
    }

    /**
     * The caller's week: every log (running ones counted to now) plus the sheet state.
     *
     * @return array<string, mixed>
     */
    public function week(User $user, Carbon|string $date): array
    {
        $start = self::weekStart($date);
        $sheet = $this->sheet($user->id, $start);
        $logs = $this->logs($user->id, $start);

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $start->copy()->addDays(6)->toDateString(),
            'status' => $sheet?->status ?? Timesheet::OPEN,
            'decision_note' => $sheet?->decision_note,
            'locked' => (bool) $sheet?->locksLogs(),
            'total_minutes' => $logs->sum('minutes'),
            'billable_minutes' => $logs->where('billable', true)->sum('minutes'),
            'days' => $logs->groupBy('date')->map(fn ($g, $date) => ['date' => $date, 'minutes' => $g->sum('minutes')])->values(),
            'entries' => $logs->groupBy('task_id')->map(function ($g) {
                $first = $g->first();

                return [
                    'task_id' => $first['task_id'], 'task_key' => $first['task_key'], 'task_title' => $first['task_title'],
                    'project' => $first['project'], 'minutes' => $g->sum('minutes'), 'billable_minutes' => $g->where('billable', true)->sum('minutes'),
                ];
            })->values(),
        ];
    }

    public function submit(User $user, Carbon|string $date): Timesheet
    {
        $start = self::weekStart($date);

        if ($start->copy()->addWeek()->isFuture() && $start->isFuture()) {
            throw ValidationException::withMessages(['week' => __('You cannot submit a week that has not started.')]);
        }

        $sheet = $this->sheet($user->id, $start);

        if ($sheet?->locksLogs()) {
            throw ValidationException::withMessages(['form' => __('This timesheet is already :status.', ['status' => __($sheet->status)])]);
        }

        $running = $this->logsQuery($user->id, $start)->whereNull('ended_at')->exists();
        if ($running) {
            throw ValidationException::withMessages(['form' => __('Stop your running timer before submitting the week.')]);
        }

        $logs = $this->logs($user->id, $start);
        if ($logs->isEmpty()) {
            throw ValidationException::withMessages(['form' => __('There is no time logged this week.')]);
        }

        // Looked up with whereDate (the `date` cast serialises with a time part on sqlite, so an exact
        // string match would miss the row and trip the unique index).
        $sheet ??= new Timesheet(['user_id' => $user->id, 'week_start' => $start->toDateString()]);
        $sheet->fill([
            'status' => Timesheet::SUBMITTED,
            'total_minutes' => $logs->sum('minutes'),
            'billable_minutes' => $logs->where('billable', true)->sum('minutes'),
            'submitted_at' => now(),
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
        ])->save();

        return $sheet;
    }

    public function decide(Timesheet $sheet, User $approver, bool $approve, ?string $note): Timesheet
    {
        if (! $this->canDecide($approver, $sheet)) {
            abort(403, __('You cannot decide this timesheet.'));
        }

        if ($sheet->status !== Timesheet::SUBMITTED) {
            throw ValidationException::withMessages(['form' => __('Only a submitted timesheet can be decided.')]);
        }

        if (! $approve && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => __('Say what needs fixing.')]);
        }

        $sheet->update([
            'status' => $approve ? Timesheet::APPROVED : Timesheet::REJECTED,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'decision_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
        ]);

        return $sheet->refresh();
    }

    /** Admins, or a lead of any project that has time on the sheet — never the owner. */
    public function canDecide(User $approver, Timesheet $sheet): bool
    {
        if ($approver->id === $sheet->user_id) {
            return false;
        }

        if ($approver->hasPermission('workspaces.manage')) {
            return true;
        }

        $projectIds = $this->logsQuery($sheet->user_id, Carbon::parse($sheet->week_start))->join('tasks', 'tasks.id', '=', 'work_logs.task_id')
            ->distinct()->pluck('tasks.project_id');

        return DB::table('project_members')
            ->join('project_roles', 'project_roles.id', '=', 'project_members.project_role_id')
            ->where('project_members.user_id', $approver->id)
            ->whereIn('project_members.project_id', $projectIds)
            ->where('project_roles.slug', 'lead')
            ->exists();
    }

    /** @return Collection<int, Timesheet> submitted sheets this approver may decide */
    public function pendingFor(User $approver)
    {
        return Timesheet::query()
            ->with('user:id,name,email')
            ->where('status', Timesheet::SUBMITTED)
            ->where('user_id', '!=', $approver->id)
            ->orderBy('submitted_at')
            ->get()
            ->filter(fn (Timesheet $s) => $this->canDecide($approver, $s))
            ->values();
    }

    private function sheet(int $userId, Carbon $start): ?Timesheet
    {
        return Timesheet::query()->where('user_id', $userId)->whereDate('week_start', $start->toDateString())->first();
    }

    private function logsQuery(int $userId, Carbon $start): Builder
    {
        return WorkLog::query()
            ->where('work_logs.user_id', $userId)
            ->where('work_logs.started_at', '>=', $start)
            ->where('work_logs.started_at', '<', $start->copy()->addWeek());
    }

    /** @return Collection<int, array<string, mixed>> */
    private function logs(int $userId, Carbon $start)
    {
        return $this->logsQuery($userId, $start)
            ->with('task:id,key,title,project_id', 'task.project:id,name')
            ->get()
            ->map(function (WorkLog $log): array {
                $minutes = $log->ended_at === null
                    ? max(1, (int) round(abs(now()->diffInSeconds($log->started_at)) / 60))
                    : $log->duration_minutes;

                return [
                    'task_id' => $log->task_id,
                    'task_key' => $log->task?->key,
                    'task_title' => $log->task?->title,
                    'project' => $log->task?->project?->name,
                    'date' => $log->started_at->toDateString(),
                    'minutes' => $minutes,
                    'billable' => (bool) $log->billable,
                ];
            });
    }
}
