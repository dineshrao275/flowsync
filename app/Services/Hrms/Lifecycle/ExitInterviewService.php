<?php

namespace App\Services\Hrms\Lifecycle;

use App\Models\Hrms\Lifecycle\ExitInterview;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — the exit interview.
 *
 * One per exit case, created on first touch. The leaver fills it in themself (or HR records it on
 * their behalf, or waives it). Individual answers are readable only by HR with offboarding view;
 * the insights view aggregates across leavers and refuses to show a breakdown below
 * `hrms.exit_interview.min_group` completed interviews, so a small team cannot de-anonymise one person.
 */
class ExitInterviewService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return list<array<string, mixed>> */
    public function questions(): array
    {
        return config('hrms.exit_interview.questions', []);
    }

    public function forCase(OffboardingCase $case): ExitInterview
    {
        return ExitInterview::query()->firstOrCreate(
            ['offboarding_case_id' => $case->id],
            ['employee_id' => $case->employee_id, 'status' => ExitInterview::PENDING],
        );
    }

    /**
     * @param  array<string, mixed>  $answers  question key => value
     *
     * @throws ValidationException on a missing required answer or an answer outside the question's options
     */
    public function submit(ExitInterview $interview, array $answers, User $by): ExitInterview
    {
        if ($interview->status === ExitInterview::WAIVED) {
            throw ValidationException::withMessages(['form' => __('This exit interview was waived.')]);
        }

        $clean = $this->validate($answers);

        $interview->update([
            'answers' => $clean,
            'status' => ExitInterview::COMPLETED,
            'recorded_by_user_id' => $by->id,
            'completed_at' => now(),
        ]);

        // The audit row says it was completed — never what was said.
        $this->audit->log($interview, 'exit_interview.completed', null, ['status' => ExitInterview::COMPLETED], $by);

        return $interview->refresh();
    }

    public function waive(ExitInterview $interview, User $by): ExitInterview
    {
        if ($interview->status === ExitInterview::COMPLETED) {
            throw ValidationException::withMessages(['form' => __('A completed exit interview cannot be waived.')]);
        }

        $interview->update(['status' => ExitInterview::WAIVED, 'recorded_by_user_id' => $by->id, 'completed_at' => now()]);
        $this->audit->log($interview, 'exit_interview.waived', null, ['status' => ExitInterview::WAIVED], $by);

        return $interview->refresh();
    }

    /**
     * Counts and averages across completed interviews; null while there are too few to be anonymous.
     *
     * @return array<string, mixed>
     */
    public function insights(): array
    {
        $min = (int) config('hrms.exit_interview.min_group', 5);
        $done = ExitInterview::query()->where('status', ExitInterview::COMPLETED)->get();

        if ($done->count() < $min) {
            return ['completed' => $done->count(), 'min_group' => $min, 'enough' => false, 'questions' => []];
        }

        $out = [];
        foreach ($this->questions() as $q) {
            $values = $done->map(fn ($i) => $i->answers[$q['key']] ?? null)->filter(fn ($v) => $v !== null && $v !== '');

            $out[$q['key']] = match ($q['type']) {
                'scale' => ['type' => 'scale', 'label' => $q['label'], 'answered' => $values->count(), 'average' => $values->count() ? round($values->avg(), 2) : null],
                'choice' => ['type' => 'choice', 'label' => $q['label'], 'answered' => $values->count(), 'counts' => $values->countBy()->all()],
                default => ['type' => 'text', 'label' => $q['label'], 'answered' => $values->count()], // words are never aggregated
            };
        }

        return ['completed' => $done->count(), 'min_group' => $min, 'enough' => true, 'questions' => $out];
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function validate(array $answers): array
    {
        $errors = [];
        $clean = [];

        foreach ($this->questions() as $q) {
            $value = $answers[$q['key']] ?? null;
            $empty = $value === null || $value === '';

            if ($empty) {
                if ($q['required'] ?? false) {
                    $errors["answers.{$q['key']}"] = __('This question needs an answer.');
                }

                continue;
            }

            $ok = match ($q['type']) {
                'scale' => is_numeric($value) && (int) $value >= 1 && (int) $value <= 5,
                'choice' => in_array($value, $q['options'] ?? [], true),
                default => is_string($value) && mb_strlen($value) <= 2000,
            };

            if (! $ok) {
                $errors["answers.{$q['key']}"] = __('That is not a valid answer.');

                continue;
            }

            $clean[$q['key']] = $q['type'] === 'scale' ? (int) $value : $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
