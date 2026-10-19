<?php

namespace App\Services\Integrations;

use App\Enums\Hrms\LeaveRequestStatus;
use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\User;
use App\Support\Hrms\HrmsSchema;
use App\Support\Tms\TmsSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The iCalendar (RFC 5545) feed behind a personal subscription URL (F10.7): the user's open assigned tasks with a due
 * date, and their approved leave. Rows come through the same visibility rule as the rest of the app, text is escaped per
 * the RFC, and lines are folded at 75 octets so strict clients accept the file.
 */
class CalendarFeed
{
    use ScopesVisibleTasks;

    /** @return array{token: string, hash: string} */
    public function mint(): array
    {
        $token = Str::random(40);

        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public function build(User $user, int $tenantId): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FlowSync//Calendar//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:FlowSync'];
        $stamp = Carbon::now('UTC')->format('Ymd\THis\Z');

        if (TmsSchema::present()) {
            $this->visibleTaskQuery($user)->where('tasks.assignee_id', $user->id)->whereNull('tasks.completed_at')->whereNotNull('tasks.due_date')
                ->whereDate('tasks.due_date', '>=', now()->subDays(30))->whereDate('tasks.due_date', '<=', now()->addYear())->orderBy('tasks.due_date')->limit(500)->get()
                ->each(function ($t) use (&$lines, $stamp, $tenantId): void {
                    $day = Carbon::parse($t->due_date);
                    array_push($lines, 'BEGIN:VEVENT', "UID:task-{$tenantId}-{$t->id}@flowsync", "DTSTAMP:$stamp", 'DTSTART;VALUE=DATE:'.$day->format('Ymd'),
                        'DTEND;VALUE=DATE:'.$day->copy()->addDay()->format('Ymd'), 'SUMMARY:'.self::esc("[{$t->key}] {$t->title}"),
                        'URL:'.url("/app/projects/{$t->project_id}?tab=tasks&task={$t->key}"), 'END:VEVENT');
                });
        }

        if (HrmsSchema::present() && ($employeeId = Employee::query()->where('user_id', $user->id)->value('id'))) {
            LeaveRequest::query()->where('employee_id', $employeeId)->where('status', LeaveRequestStatus::Approved->value)
                ->whereDate('to_date', '>=', now()->subDays(30))->orderBy('from_date')->limit(200)->get()
                ->each(function ($l) use (&$lines, $stamp, $tenantId): void {
                    array_push($lines, 'BEGIN:VEVENT', "UID:leave-{$tenantId}-{$l->id}@flowsync", "DTSTAMP:$stamp", 'DTSTART;VALUE=DATE:'.$l->from_date->format('Ymd'),
                        'DTEND;VALUE=DATE:'.$l->to_date->copy()->addDay()->format('Ymd'), 'SUMMARY:'.self::esc(__('Leave')), 'TRANSP:TRANSPARENT', 'END:VEVENT');
                });
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    public static function esc(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** Fold a content line at 75 octets without splitting a multibyte character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $chunk = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($chunk.$ch) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ").$chunk;
                $chunk = '';
            }
            $chunk .= $ch;
        }

        return $out."\r\n ".$chunk;
    }
}
