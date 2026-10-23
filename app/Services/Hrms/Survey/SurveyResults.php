<?php

declare(strict_types=1);

namespace App\Services\Hrms\Survey;

use App\Models\Hrms\Survey\SurveyAnswer;
use App\Models\Hrms\Survey\SurveyCampaign;
use App\Models\Hrms\Survey\SurveyQuestion;
use App\Models\Hrms\Survey\SurveyResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Survey results: the anonymity-threshold gate, per-question aggregates (NPS, yes/no, choice, text,
 * numeric), the persisted snapshot rows and the five-minute cache. Extracted from EngagementService
 * unchanged; closing and answering bust the cache through resultsKey().
 */
class SurveyResults
{
    /**
     * The aggregates, cached warm: per-question rows plus the headcount and
     * the threshold beside them. Below the threshold the reader gets the
     * counts and nothing else — no snapshot rows, because a row saying
     * "three people answered" next to a locked campaign is itself a
     * disclosure. Anonymous free text redacts to counts at any level.
     *
     * @return array{results: list<array<string, mixed>>, response_count: int, anonymity_threshold: int}
     */
    public function results(SurveyCampaign $campaign): array
    {
        return Cache::remember(self::resultsKey($campaign), 300, function () use ($campaign): array {
            $count = $campaign->responses()->whereNotNull('submitted_at')->count();

            if ($count < $campaign->anonymity_threshold) {
                return ['results' => [], 'response_count' => $count, 'anonymity_threshold' => $campaign->anonymity_threshold];
            }

            $rows = [];

            foreach ($campaign->template->questions()->orderBy('sequence')->get() as $question) {
                $aggregates = $this->aggregate($campaign, $question);

                $rows[] = [
                    'question_id' => $question->id,
                    'type' => $question->type->value,
                    'text' => $question->text,
                    'aggregates' => $aggregates,
                    'response_count' => $count,
                ];

                SurveyResult::query()->updateOrCreate(
                    ['campaign_id' => $campaign->id, 'question_id' => $question->id],
                    ['aggregates' => $aggregates, 'response_count' => $count, 'computed_at' => now()],
                );
            }

            return ['results' => $rows, 'response_count' => $count, 'anonymity_threshold' => $campaign->anonymity_threshold];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregate(SurveyCampaign $campaign, SurveyQuestion $question): array
    {
        $values = SurveyAnswer::query()->where('question_id', $question->id)
            ->whereHas('response', fn ($query) => $query
                ->where('campaign_id', $campaign->id)
                ->whereNotNull('submitted_at'))
            ->get();

        $numbers = $values->pluck('value_number')->filter(fn ($value): bool => $value !== null)->map(fn ($value): float => (float) $value);

        return match ($question->type->value) {
            'nps' => $this->aggregateNps($numbers),
            'yes_no' => [
                'yes' => $numbers->filter(fn (float $value): bool => $value >= 1)->count(),
                'no' => $numbers->filter(fn (float $value): bool => $value < 1)->count(),
            ],
            'multiple_choice' => $values->pluck('value_json')->filter()->flatten()->countBy()->all(),
            'text' => $campaign->template->is_anonymous
                ? ['responses' => $values->whereNotNull('value_text')->count()]
                : ['responses' => $values->pluck('value_text')->filter()->values()->all()],
            default => [
                'count' => $numbers->count(),
                'average' => $numbers->isEmpty() ? null : round($numbers->avg(), 2),
                'min' => $numbers->isEmpty() ? null : (float) $numbers->min(),
                'max' => $numbers->isEmpty() ? null : (float) $numbers->max(),
            ],
        };
    }

    /**
     * @param  Collection<int, float>  $numbers
     * @return array<string, mixed>
     */
    private function aggregateNps(Collection $numbers): array
    {
        $total = $numbers->count();
        $promoters = $numbers->filter(fn (float $value): bool => $value >= 9)->count();
        $detractors = $numbers->filter(fn (float $value): bool => $value <= 6)->count();

        return [
            'promoters' => $promoters,
            'passives' => $total - $promoters - $detractors,
            'detractors' => $detractors,
            'score' => $total === 0 ? null : (int) round(($promoters - $detractors) / $total * 100),
        ];
    }

    public static function resultsKey(SurveyCampaign $campaign): string
    {
        return "hrms.survey.results.{$campaign->id}";
    }
}
