<?php

namespace App\Services\Hrms\Attendance;

use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchKind;
use App\Models\Hrms\Attendance\AttendancePunch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Attendance/HRMS — turn a day's punches into worked and break minutes.
 *
 * Pair in→out in order. Stray outs (no open in) are ignored, consecutive ins
 * keep the earliest open one, and a trailing open in contributes no minutes —
 * an unclosed session is time nobody can verify, and crediting it would pay for
 * hours that may never have happened.
 *
 * Break punches (`kind = break`) take part in the pairing like any other
 * in/out — a break-out closes the working session and the break-in opens the
 * next — so the gap between them is simply not worked. They never become the
 * day's first-in or last-out, and the minutes between a break-out and its
 * matching break-in are reported as *actual* break time, which replaces the
 * shift's standard break deduction for that day.
 */
class PunchPairing
{
    /**
     * @param  Collection<int, AttendancePunch>  $punches  ordered by time
     * @return array{0: Carbon|null, 1: Carbon|null, 2: int, 3: int, 4: bool} first in, last out, worked, break, hasBreak
     */
    public function pair(Collection $punches): array
    {
        $firstIn = null;
        $lastOut = null;
        $worked = 0;
        $open = null;
        $breakStart = null;
        $break = 0;
        $hasBreak = false;

        foreach ($punches as $punch) {
            $isBreak = $punch->kind === PunchKind::Break;
            $hasBreak = $hasBreak || $isBreak;

            if ($punch->direction === PunchDirection::In) {
                if ($isBreak && $breakStart !== null) {
                    // abs() and int: Carbon 3 diffs are signed floats (the
                    // same trap the work-log duration and tenure math learned).
                    $break += (int) abs($punch->punch_at->diffInMinutes($breakStart));
                    $breakStart = null;
                }

                if (! $isBreak) {
                    $firstIn ??= $punch->punch_at;
                }
                $open ??= $punch->punch_at;

                continue;
            }

            if ($isBreak) {
                $breakStart = $punch->punch_at;
            } else {
                $lastOut = $punch->punch_at;
            }

            if ($open !== null) {
                $worked += (int) abs($punch->punch_at->diffInMinutes($open));
                $open = null;
            }
        }

        return [$firstIn, $lastOut, $worked, $break, $hasBreak];
    }
}
