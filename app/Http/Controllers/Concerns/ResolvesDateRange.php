<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The optional ?from=&to= (YYYY-MM-DD) window shared by the dashboards.
 * Defaults to the last 14 days and is capped at 366 days.
 */
trait ResolvesDateRange
{
    /**
     * @param  bool  $nullWhenUnfiltered  return null (instead of the default window) when neither bound is sent
     * @return array{from: string, to: string, days: int}|null
     */
    protected function resolveDateRange(Request $request, bool $nullWhenUnfiltered = false): ?array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($nullWhenUnfiltered && ! isset($data['from']) && ! isset($data['to'])) {
            return null;
        }

        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(13);

        if ($from->diffInDays($to) > 365) {
            abort(422, 'Range covers at most 366 days.');
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
        ];
    }
}
