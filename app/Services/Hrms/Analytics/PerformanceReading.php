<?php

namespace App\Services\Hrms\Analytics;

use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Hrms\Performance\ReviewSummary;
use Illuminate\Support\Facades\DB;

/**
 * Analytics/HRMS — counts always, ratings on permission.
 *
 * Goals by status and review completion travel to every reader; average
 * ratings travel only to talent viewers, and only from non-peer cycles —
 * averages cannot name anyone, but a peer cycle's promise says even
 * aggregates stay in, so they do.
 */
class PerformanceReading extends AnalyticsReading
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = [], bool $includeRatings = false): array
    {
        return $this->remember('performance', $filters + ['ratings' => $includeRatings], function () use ($includeRatings): array {
            $goals = PerformanceGoal::query()
                ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();

            $total = ReviewSummary::query()->count();
            $done = ReviewSummary::query()->whereIn('status', ['final', 'acknowledged'])->count();

            return [
                'goals_by_status' => $goals,
                'review_completion_percent' => $total === 0 ? 0.0 : round($done / $total * 100, 1),
                'ratings' => $includeRatings ? $this->ratings() : null,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function ratings(): ?array
    {
        return PerformanceCycle::query()
            ->where('anonymity', '!=', 'peer')
            ->with(['reviewSummaries' => fn ($query) => $query->whereNotNull('manager_rating')])
            ->get()
            ->map(fn (PerformanceCycle $cycle): ?array => $cycle->reviewSummaries->isEmpty() ? null : [
                'cycle' => $cycle->name,
                'average_manager_rating' => round($cycle->reviewSummaries->avg('manager_rating'), 2),
                'reviews' => $cycle->reviewSummaries->count(),
            ])->filter()->values()->all();
    }
}
