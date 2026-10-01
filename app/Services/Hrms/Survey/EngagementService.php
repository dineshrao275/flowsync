<?php

namespace App\Services\Hrms\Survey;

use App\Enums\Hrms\SurveyCampaignStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Survey\SurveyAnswer;
use App\Models\Hrms\Survey\SurveyCampaign;
use App\Models\Hrms\Survey\SurveyQuestion;
use App\Models\Hrms\Survey\SurveyResponse;
use App\Models\Hrms\Survey\SurveyResult;
use App\Models\Hrms\Survey\SurveyTemplate;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Survey/HRMS — campaigns from scheduling to results.
 *
 * Six public methods, one per lifecycle verb: schedule a template, open
 * and close the window, invite the audience, answer, and read the
 * aggregates. Anonymity is structural (no employee link on anonymous
 * rows, fingerprints constrained) and the threshold is a refusal, not an
 * error — below it the reader gets an empty result with the count beside
 * it, never partial rows and never free text. Results cache warm for five
 * minutes; closing and answering bust it.
 */
class EngagementService
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function schedule(SurveyTemplate $template, array $data, ?User $actor = null): SurveyCampaign
    {
        $campaign = SurveyCampaign::create([
            'template_id' => $template->id,
            'name' => $data['name'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'status' => SurveyCampaignStatus::Scheduled,
            'anonymity_threshold' => $data['anonymity_threshold'] ?? 5,
            'notify_on_publish' => (bool) ($data['notify_on_publish'] ?? true),
            'created_by' => $actor?->id,
        ]);

        $this->audit->log($campaign, 'survey.scheduled', null, [
            'template_id' => $template->id,
        ], $actor);

        return $campaign->refresh();
    }

    /**
     * Open the window — and invite when the campaign says to. Publishing
     * is the event the audience hears about, not scheduling, because a
     * scheduled campaign may still move.
     *
     * @throws ValidationException outside scheduled
     */
    public function open(SurveyCampaign $campaign, ?User $actor = null): SurveyCampaign
    {
        $this->requireStatus($campaign, SurveyCampaignStatus::Scheduled, 'Only a scheduled campaign opens.');

        return DB::transaction(function () use ($campaign, $actor): SurveyCampaign {
            $campaign->update(['status' => SurveyCampaignStatus::Open]);

            $invited = $campaign->notify_on_publish ? $this->invite($campaign->refresh(), $actor) : 0;

            $this->audit->log($campaign->refresh(), 'survey.opened', null, [
                'invited' => $invited,
            ], $actor);

            return $campaign->refresh();
        });
    }

    /**
     * Close the window: answers stop, the results cache busts so the next
     * read recomputes the final snapshot, and the row seals.
     *
     * @throws ValidationException outside open
     */
    public function close(SurveyCampaign $campaign, ?User $actor = null): SurveyCampaign
    {
        $this->requireStatus($campaign, SurveyCampaignStatus::Open, 'Only an open campaign closes.');

        return DB::transaction(function () use ($campaign, $actor): SurveyCampaign {
            $campaign->update(['status' => SurveyCampaignStatus::Closed]);
            Cache::forget($this->resultsKey($campaign));

            $this->audit->log($campaign->refresh(), 'survey.closed', null, [
                'responses' => $campaign->responses()->whereNotNull('submitted_at')->count(),
            ], $actor);

            return $campaign->refresh();
        });
    }

    /**
     * Resolve the audience and tell them: everyone with a login hears,
     * the actor does not toast themselves. Returns the headcount, not the
     * rows — the notification ledger already records who heard what.
     */
    public function invite(SurveyCampaign $campaign, ?User $actor = null): int
    {
        $invited = 0;

        foreach ($this->audience($campaign) as $employee) {
            if ($employee->user_id === null) {
                continue;
            }

            $recipient = User::find((int) $employee->user_id);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $this->notifications->notify($recipient, 'hrms.survey.invited', [
                'survey_campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
            ], $actor);
            $invited++;
        }

        return $invited;
    }

    /**
     * Answer a campaign: one submission, enforced by the database with a
     * friendly check first. Required questions must be answered; numeric
     * answers obey the question's bounds; exactly one value column lands
     * per row, chosen by the question type. Answer values never enter the
     * audit trail — least of all free text on an anonymous survey.
     *
     * @param  list<array{question_id: int, value?: mixed}>  $answers
     * @param  array{ip?: string|null, user_agent?: string|null}  $meta
     *
     * @throws ValidationException outside open, on missing required
     *                             answers, or on a second submission
     */
    public function respond(
        SurveyCampaign $campaign,
        ?Employee $employee,
        array $answers,
        array $meta = [],
        ?User $actor = null,
    ): SurveyResponse {
        $this->requireStatus($campaign, SurveyCampaignStatus::Open, 'That campaign is not open for answers.');

        $anonymous = $campaign->template->is_anonymous;
        $fingerprint = $anonymous ? $this->fingerprint($campaign, $meta) : null;

        if ($employee !== null && $this->answered($campaign, $employee, $fingerprint)) {
            throw ValidationException::withMessages(['form' => 'That has already been answered — one submission per campaign.']);
        }

        $rows = $this->validatedAnswers($campaign, $answers);

        try {
            return DB::transaction(function () use ($campaign, $anonymous, $employee, $fingerprint, $rows, $meta, $actor): SurveyResponse {
                $response = SurveyResponse::create([
                    'campaign_id' => $campaign->id,
                    'employee_id' => $anonymous ? null : $employee?->id,
                    'respondent_key' => $fingerprint,
                    'started_at' => now(),
                    'submitted_at' => now(),
                    'ip_hash' => isset($meta['ip']) ? hash('sha256', (string) $meta['ip']) : null,
                    'user_agent' => isset($meta['user_agent']) ? mb_substr((string) $meta['user_agent'], 0, 255) : null,
                ]);

                foreach ($rows as $row) {
                    $response->answers()->create($row);
                }

                Cache::forget($this->resultsKey($campaign));

                $this->audit->log($response->refresh(), 'survey.answered', null, [
                    'campaign_id' => $campaign->id,
                    'questions' => count($rows),
                ], $actor);

                return $response->refresh();
            });
        } catch (QueryException $exception) {
            throw ValidationException::withMessages(['form' => 'That has already been answered — one submission per campaign.']);
        }
    }

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
        return Cache::remember($this->resultsKey($campaign), 300, function () use ($campaign): array {
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
     * Everyone the campaign addresses, active records only: exits do not
     * answer pulses. Deterministic by id — the same audience every run,
     * so an invite re-run nudges the same people instead of sampling.
     *
     * @return Collection<int, Employee>
     */
    private function audience(SurveyCampaign $campaign): Collection
    {
        $meta = $campaign->template->audience_meta ?? [];
        $scope = $campaign->template->audience_scope->value;

        $query = Employee::query()->active()->orderBy('id');

        match ($scope) {
            'department' => $query->where('department_id', (int) ($meta['department_id'] ?? 0)),
            'location' => $query->where('location_id', (int) ($meta['location_id'] ?? 0)),
            'role' => $query->whereIn('user_id', User::query()
                ->whereHas('roles', fn ($roles) => $roles->where('roles.id', (int) ($meta['role_id'] ?? 0)))
                ->pluck('id')),
            'explicit' => $query->whereIn('id', array_map('intval', (array) ($meta['employee_ids'] ?? []))),
            default => null,
        };

        return $query->get();
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

    /**
     * @param  list<array{question_id: int, value?: mixed}>  $answers
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException on missing required answers or
     *                             out-of-bounds numbers
     */
    private function validatedAnswers(SurveyCampaign $campaign, array $answers): array
    {
        $byQuestion = [];

        foreach ($answers as $answer) {
            $byQuestion[(int) ($answer['question_id'] ?? 0)] = $answer['value'] ?? null;
        }

        $rows = [];

        foreach ($campaign->template->questions()->orderBy('sequence')->get() as $question) {
            $value = $byQuestion[$question->id] ?? null;

            if ($question->is_required && ($value === null || $value === '' || $value === [])) {
                throw ValidationException::withMessages(["questions.{$question->id}" => 'That question needs an answer.']);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $rows[] = ['question_id' => $question->id, ...$this->answerRow($question, $value)];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException on an out-of-bounds number
     */
    private function answerRow(SurveyQuestion $question, mixed $value): array
    {
        return match ($question->type->value) {
            'text' => ['value_text' => mb_substr((string) $value, 0, 5000)],
            'multiple_choice' => ['value_json' => array_values((array) $value)],
            default => ['value_number' => $this->boundedNumber($question, $value)],
        };
    }

    /**
     * @throws ValidationException on an out-of-bounds number
     */
    private function boundedNumber(SurveyQuestion $question, mixed $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(["questions.{$question->id}" => 'That answer must be a number.']);
        }

        foreach (['min' => $question->min, 'max' => $question->max] as $bound => $limit) {
            if ($limit !== null && ($bound === 'min' ? (float) $value < (float) $limit : (float) $value > (float) $limit)) {
                throw ValidationException::withMessages(["questions.{$question->id}" => "That answer falls outside {$question->min}–{$question->max}."]);
            }
        }

        return (string) $value;
    }

    private function answered(SurveyCampaign $campaign, ?Employee $employee, ?string $fingerprint): bool
    {
        if ($employee !== null && SurveyResponse::query()
            ->where('campaign_id', $campaign->id)
            ->where('employee_id', $employee->id)
            ->exists()) {
            return true;
        }

        return $fingerprint !== null && SurveyResponse::query()
            ->where('campaign_id', $campaign->id)
            ->where('respondent_key', $fingerprint)
            ->exists();
    }

    private function fingerprint(SurveyCampaign $campaign, array $meta): string
    {
        return hash('md5', $campaign->id.'.'.($meta['ip'] ?? '').'.'.($meta['user_agent'] ?? ''));
    }

    /**
     * @throws ValidationException on any other status
     */
    private function requireStatus(SurveyCampaign $campaign, SurveyCampaignStatus $expected, string $message): void
    {
        if ($campaign->status !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function resultsKey(SurveyCampaign $campaign): string
    {
        return "hrms.survey.results.{$campaign->id}";
    }
}
