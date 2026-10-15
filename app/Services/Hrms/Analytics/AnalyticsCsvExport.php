<?php

namespace App\Services\Hrms\Analytics;

use Illuminate\Support\Carbon;

/**
 * Analytics/HRMS — flattening one dashboard domain into CSV rows.
 *
 * The JSON payloads stay canonical; this is the lossy convenience form for
 * spreadsheets. Every domain emits a uniform four-column shape
 * (`section, item, value, detail`) so one writer serves all seven tabs and
 * the frontend needs no per-domain parser. Names ride along (a digest that
 * names nobody is useless in a spreadsheet), but never pay internals beyond
 * the totals the payroll tab already shows — and a `null` tier (withheld
 * liability, withheld ratings) renders as an empty cell, never as zero.
 */
class AnalyticsCsvExport
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function forDomain(string $domain, array $data): array
    {
        $rows = match ($domain) {
            'attendance' => $this->attendance($data),
            'leave' => $this->leave($data),
            'lifecycle' => $this->lifecycle($data),
            'performance' => $this->performance($data),
            'payroll' => $this->payroll($data),
            'documents' => $this->documents($data),
            'assets' => $this->assets($data),
            default => [],
        };

        return [
            'headers' => ['section', 'item', 'value', 'detail'],
            'rows' => array_map(fn ($row) => array_map(fn ($cell) => (string) $cell, $row), $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string|int|float|null>>
     */
    private function attendance(array $data): array
    {
        $rows = [];

        foreach (['present_days', 'absent_days', 'late_days', 'overtime_minutes', 'leave_days', 'average_worked_hours'] as $key) {
            $rows[] = ['summary', $key, $data[$key] ?? '', ''];
        }

        foreach (['top_late', 'top_overtime'] as $section) {
            foreach ((array) ($data[$section] ?? []) as $entry) {
                $rows[] = [$section, (string) ($entry['name'] ?? ''), $entry['minutes'] ?? '', 'minutes'];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string|int|float|null>>
     */
    private function leave(array $data): array
    {
        $rows = [['summary', 'pending_approvals', $data['pending_approvals'] ?? '', '']];

        foreach ((array) ($data['by_type'] ?? []) as $type) {
            $rows[] = ['by_type', (string) ($type['type'] ?? ''), $type['days'] ?? '', ($type['requests'] ?? '').' requests'];
        }

        foreach ((array) ($data['pending_items'] ?? []) as $item) {
            $rows[] = [
                'pending',
                (string) ($item['employee'] ?? ''),
                ($item['from_date'] ?? '').' to '.($item['to_date'] ?? ''),
                'request #'.($item['id'] ?? ''),
            ];
        }

        foreach ((array) ($data['top_consumers'] ?? []) as $entry) {
            $rows[] = ['top_consumers', (string) ($entry['name'] ?? ''), $entry['days'] ?? '', 'days'];
        }

        $rows[] = ['summary', 'expiry_liability', $data['expiry_liability'] ?? '', 'withheld renders empty'];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string|int|float|null>>
     */
    private function lifecycle(array $data): array
    {
        $rows = [];

        foreach (['onboarding_avg_days', 'offboarding_avg_days', 'onboarding_completion_percent', 'offboarding_completion_percent', 'time_to_first_day_avg'] as $key) {
            $rows[] = ['summary', $key, $data[$key] ?? '', ''];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string|int|float|null>>
     */
    private function performance(array $data): array
    {
        $rows = [['summary', 'review_completion_percent', $data['review_completion_percent'] ?? '', '']];

        foreach ((array) ($data['goals_by_status'] ?? []) as $status => $count) {
            $rows[] = ['goals', (string) $status, $count, ''];
        }

        foreach ((array) ($data['ratings'] ?? []) as $rating) {
            $rows[] = [
                'ratings',
                (string) ($rating['cycle'] ?? ''),
                $rating['average_manager_rating'] ?? '',
                ($rating['reviews'] ?? '').' reviews',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string|int|float|null>>
     */
    private function payroll(array $data): array
    {
        $rows = [];

        foreach (['total_gross', 'total_net', 'total_annual_ctc'] as $key) {
            $rows[] = ['summary', $key, $data[$key] ?? '', ''];
        }

        foreach ((array) ($data['department_cost'] ?? []) as $entry) {
            $rows[] = ['department_cost', (string) ($entry['department'] ?? ''), $entry['annual_ctc'] ?? '', ''];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string|int|float|null>>
     */
    private function documents(array $data): array
    {
        $rows = [];

        foreach ((array) ($data['compliance'] ?? []) as $entry) {
            $rows[] = [
                'compliance',
                (string) ($entry['type'] ?? ''),
                $entry['percent'] ?? '',
                ($entry['holders'] ?? '').' holders',
            ];
        }

        foreach ((array) ($data['expiring'] ?? []) as $window => $bucket) {
            foreach ((array) ($bucket['items'] ?? []) as $item) {
                $rows[] = [
                    "expiring_{$window}",
                    (string) ($item['title'] ?? ''),
                    $this->dateCell($item['expires_at'] ?? null),
                    (string) ($item['employee'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string|int|float|null>>
     */
    private function assets(array $data): array
    {
        $rows = [];

        foreach ((array) ($data['by_status'] ?? []) as $status => $count) {
            $rows[] = ['by_status', (string) $status, $count, ''];
        }

        foreach ((array) ($data['per_department'] ?? []) as $entry) {
            $rows[] = ['per_department', (string) ($entry['department'] ?? ''), $entry['assigned'] ?? '', ''];
        }

        return $rows;
    }

    private function dateCell(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return (string) ($value ?? '');
    }
}
