<?php

namespace App\Services\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\User;
use App\Support\Hrms\CsvTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Holiday/HRMS — bring a calendar in from a CSV or an iCalendar (.ics) file.
 *
 * Two readers, one validation: both yield `{line, name, date, type,
 * is_recurring}` rows, which are checked (name, real date, known type), compared
 * with what the calendar already holds (same date *and* name = a duplicate, in
 * the file or in the calendar) and only then written through
 * `HolidayCalendarService::createHoliday()` so every row gets its audit entry.
 * `preview()` never writes. CSV columns: `name,date[,type,is_recurring]`; ICS
 * reads each VEVENT's SUMMARY and DTSTART (all-day multi-day events expand to
 * one holiday per day, capped), and a yearly RRULE marks the holiday recurring.
 */
class HolidayImporter
{
    public const MAX_ROWS = 500;

    private const MAX_EVENT_DAYS = 31;

    public const SAMPLE = [
        ['name', 'date', 'type', 'is_recurring'],
        ["New Year's Day", '2027-01-01', 'public', 'yes'],
        ['Founders Day', '2027-03-15', 'optional', 'no'],
    ];

    public function __construct(private readonly HolidayCalendarService $calendars) {}

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException on an unreadable file
     */
    public function parse(UploadedFile $file): array
    {
        $extension = Str::lower($file->getClientOriginalExtension());

        return $extension === 'ics'
            ? $this->parseIcs((string) file_get_contents($file->getRealPath()))
            : array_map(fn (array $row): array => [
                'line' => $row['line'],
                'name' => $row['name'] ?? '',
                'date' => $row['date'] ?? '',
                'type' => Str::lower($row['type'] ?? '') ?: 'public',
                'is_recurring' => in_array(Str::lower($row['is_recurring'] ?? ''), ['1', 'yes', 'true', 'y'], true),
            ], CsvTable::read($file, ['name', 'date'], self::MAX_ROWS));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, new: int, duplicates: int, invalid: int}
     */
    public function evaluate(HolidayCalendar $calendar, array $rows): array
    {
        $existing = $calendar->holidays()->get(['name', 'date'])
            ->map(fn ($h) => $h->date->toDateString().'|'.Str::lower($h->name))->flip()->all();
        $seen = [];
        $out = [];
        $counts = ['new' => 0, 'duplicates' => 0, 'invalid' => 0];

        foreach ($rows as $row) {
            $error = $this->rowError($row);
            $key = $error === null ? $row['date'].'|'.Str::lower($row['name']) : null;
            $status = $error !== null ? 'invalid' : (isset($existing[$key]) || isset($seen[$key]) ? 'duplicate' : 'new');

            $counts[match ($status) {
                'invalid' => 'invalid', 'duplicate' => 'duplicates', default => 'new'
            }]++;
            $key !== null && $seen[$key] = true;
            $out[] = $row + ['status' => $status, 'error' => $error];
        }

        return ['rows' => $out] + $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $evaluated  the `rows` of evaluate()
     * @return array{created: int, skipped: int}
     *
     * @throws ValidationException when the file holds invalid rows and `$skipInvalid` is off
     */
    public function commit(HolidayCalendar $calendar, array $evaluated, bool $skipInvalid, ?User $actor): array
    {
        $invalid = count(array_filter($evaluated, fn ($r) => $r['status'] === 'invalid'));

        if ($invalid > 0 && ! $skipInvalid) {
            throw ValidationException::withMessages(['file' => "{$invalid} row(s) have errors. Fix them or import only the valid rows."]);
        }

        $created = 0;
        foreach ($evaluated as $row) {
            if ($row['status'] !== 'new') {
                continue;
            }

            $this->calendars->createHoliday($calendar, [
                'name' => $row['name'],
                'date' => $row['date'],
                'type' => $row['type'],
                'is_recurring' => $row['is_recurring'],
            ], $actor);
            $created++;
        }

        return ['created' => $created, 'skipped' => count($evaluated) - $created];
    }

    /** @param  array<string, mixed>  $row */
    private function rowError(array $row): ?string
    {
        if ($row['name'] === '' || mb_strlen($row['name']) > 255) {
            return 'Name is required (max 255 characters).';
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $row['date']);
            if ($date === false || $date->format('Y-m-d') !== $row['date']) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            return 'Date must be a real YYYY-MM-DD date.';
        }

        return HolidayType::tryFrom((string) $row['type']) === null ? "Unknown type '{$row['type']}'." : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException when the file holds no events
     */
    private function parseIcs(string $raw): array
    {
        // RFC 5545 unfolding: a line starting with space/tab continues the previous one.
        $lines = preg_split('/\r\n|\r|\n/', preg_replace('/(\r\n|\r|\n)[ \t]/', '', $raw)) ?: [];
        $rows = [];
        $event = null;

        foreach ($lines as $index => $line) {
            if (strtoupper(trim($line)) === 'BEGIN:VEVENT') {
                $event = ['line' => $index + 1, 'summary' => '', 'start' => null, 'end' => null, 'recurring' => false];

                continue;
            }

            if ($event === null) {
                continue;
            }

            if (strtoupper(trim($line)) === 'END:VEVENT') {
                array_push($rows, ...$this->expandEvent($event));
                $event = null;

                continue;
            }

            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $property = strtoupper(explode(';', $name)[0]);

            match ($property) {
                'SUMMARY' => $event['summary'] = trim(str_replace(['\\,', '\\;', '\\n', '\\N'], [',', ';', ' ', ' '], $value)),
                'DTSTART' => $event['start'] = substr(trim($value), 0, 8),
                'DTEND' => $event['end'] = substr(trim($value), 0, 8),
                'RRULE' => $event['recurring'] = str_contains(strtoupper($value), 'FREQ=YEARLY'),
                default => null,
            };

            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'A single import is limited to '.self::MAX_ROWS.' holidays. Split the file.']);
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The calendar file holds no events.']);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<int, array<string, mixed>>
     */
    private function expandEvent(array $event): array
    {
        $start = $this->icsDate($event['start']);

        if ($start === null) {
            return [['line' => $event['line'], 'name' => $event['summary'], 'date' => (string) $event['start'], 'type' => 'public', 'is_recurring' => $event['recurring']]];
        }

        // DTEND of an all-day event is exclusive; a missing one means a single day.
        $end = $this->icsDate($event['end']);
        $days = $end === null ? 1 : max(1, min(self::MAX_EVENT_DAYS, (int) abs($end->diffInDays($start))));

        return array_map(fn (int $offset): array => [
            'line' => $event['line'],
            'name' => $event['summary'],
            'date' => $start->copy()->addDays($offset)->toDateString(),
            'type' => 'public',
            'is_recurring' => $event['recurring'],
        ], range(0, $days - 1));
    }

    private function icsDate(?string $value): ?Carbon
    {
        if ($value === null || ! preg_match('/^\d{8}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Ymd', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
