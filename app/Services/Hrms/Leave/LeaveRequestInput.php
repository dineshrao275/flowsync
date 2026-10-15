<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Leave\LeaveType;
use Illuminate\Support\Carbon;

/**
 * Leave/HRMS — a validated leave ask, before it is stored.
 *
 * The boundary object between validation and storage: the service parses
 * the request payload into this once, so the transaction body takes three
 * arguments instead of eight loose values (D2.16.3, the RegularizationInput
 * precedent). Halves ride as the raw strings the row stores — they were
 * parsed to enums for validation before this was built.
 */
final readonly class LeaveRequestInput
{
    /**
     * @param  list<array{date: string, is_holiday: bool, is_week_off: bool, is_half_day: bool}>  $split
     */
    public function __construct(
        public LeaveType $type,
        public Carbon $from,
        public Carbon $to,
        public float $total,
        public array $split,
        public string $reason,
        public ?string $contact,
        public ?int $documentId,
        public string $fromHalf,
        public string $toHalf,
    ) {}
}
