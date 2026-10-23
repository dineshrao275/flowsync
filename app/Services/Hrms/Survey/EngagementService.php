<?php

declare(strict_types=1);

namespace App\Services\Hrms\Survey;

use App\Enums\Hrms\SurveyCampaignStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Survey\SurveyCampaign;
use App\Models\Hrms\Survey\SurveyQuestion;
use App\Models\Hrms\Survey\SurveyResponse;
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
            Cache::forget(SurveyResults::resultsKey($campaign));

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
        $audience = $this->audience($campaign);
        $recipients = User::whereIn('id', $audience->pluck('user_id')->filter()->map(fn ($id) => (int) $id))->get()->keyBy('id');

        foreach ($audience as $employee) {
            if ($employee->user_id === null) {
                continue;
            }

            $recipient = $recipients->get((int) $employee->user_id);

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
            throw ValidationException::withMessages(['form' => __('That has already been answered — one submission per campaign.')]);
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

                Cache::forget(SurveyResults::resultsKey($campaign));

                $this->audit->log($response->refresh(), 'survey.answered', null, [
                    'campaign_id' => $campaign->id,
                    'questions' => count($rows),
                ], $actor);

                return $response->refresh();
            });
        } catch (QueryException $exception) {
            throw ValidationException::withMessages(['form' => __('That has already been answered — one submission per campaign.')]);
        }
    }

    /**
     * Results with the anonymity threshold applied (see SurveyResults).
     *
     * @return array{results: list<array<string, mixed>>, response_count: int, anonymity_threshold: int}
     */
    public function results(SurveyCampaign $campaign): array
    {
        return app(SurveyResults::class)->results($campaign);
    }

    /**
     * Everyone the campaign addresses, active records only: exits do not
     * answer pulses. Deterministic by id — the same audience every run,
     * so an invite re-run nudges the same people instead of sampling.
     *
     * @return Collection<int, Employee>
     */
    public function audience(SurveyCampaign $campaign): Collection
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
     * Whether a login is invited: their employment record sits in the
     * audience. The policy answers "may they respond" through here so the
     * HTTP surface and the audience rule cannot disagree about who was
     * asked.
     */
    public function isInvited(SurveyCampaign $campaign, User $user): bool
    {
        $employeeId = Employee::where('user_id', $user->id)->value('id');

        if ($employeeId === null) {
            return false;
        }

        return $this->audience($campaign)->contains(fn (Employee $employee): bool => (int) $employee->id === (int) $employeeId);
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
        $templateIds = $campaign->template->questions()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($answers as $answer) {
            if (! in_array((int) ($answer['question_id'] ?? 0), $templateIds, true)) {
                throw ValidationException::withMessages(['answers' => __('An answer names a question outside this campaign.')]);
            }
        }

        $byQuestion = [];

        foreach ($answers as $answer) {
            $byQuestion[(int) ($answer['question_id'] ?? 0)] = $answer['value'] ?? null;
        }

        $rows = [];

        foreach ($campaign->template->questions()->orderBy('sequence')->get() as $question) {
            $value = $byQuestion[$question->id] ?? null;

            if ($question->is_required && ($value === null || $value === '' || $value === [])) {
                throw ValidationException::withMessages(["questions.{$question->id}" => __('That question needs an answer.')]);
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
            throw ValidationException::withMessages(["questions.{$question->id}" => __('That answer must be a number.')]);
        }

        foreach (['min' => $question->min, 'max' => $question->max] as $bound => $limit) {
            if ($limit !== null && ($bound === 'min' ? (float) $value < (float) $limit : (float) $value > (float) $limit)) {
                throw ValidationException::withMessages(["questions.{$question->id}" => __('That answer falls outside :min–:max.', ['min' => $question->min, 'max' => $question->max])]);
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
}
