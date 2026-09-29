<?php

namespace App\Services\Hrms\Attendance;

use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — a validated correction ask, before it is stored.
 *
 * The boundary object between validation and storage: the service parses
 * the request payload into this once, so the transaction body takes three
 * arguments instead of six loose values (D2.16.3). `firstIn` is the
 * corrected clock-in, `lastOut` the corrected clock-out, both pinned to
 * `work_date` by the service before this is built.
 */
final readonly class RegularizationInput
{
    public function __construct(
        public Carbon $workDate,
        public ?Carbon $firstIn,
        public ?Carbon $lastOut,
        public string $reason,
    ) {}
}
