<?php

namespace App\Services\Hrms\Survey;

use App\Models\Hrms\Survey\SurveyTemplate;
use App\Models\User;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Survey/HRMS — questionnaire master data.
 *
 * Split from `EngagementService` the way catalogs always split from
 * lifecycles here: templates sit still while campaigns move. Questions
 * sync wholesale (one call names the full set — a payload cannot smuggle
 * a question onto another template), and a template with campaigns behind
 * it refuses deletion, because history keeps its wording.
 */
class SurveyTemplateService
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $questions
     */
    public function createTemplate(array $data, array $questions = [], ?User $actor = null): SurveyTemplate
    {
        return DB::transaction(function () use ($data, $questions, $actor): SurveyTemplate {
            $template = SurveyTemplate::create([
                ...$data,
                'slug' => $this->naming->uniqueSlug(SurveyTemplate::class, (string) $data['name']),
                'created_by' => $actor?->id,
            ]);

            $this->syncQuestions($template, $questions);
            $this->audit->log($template->refresh(), 'survey.template_created', null, [
                'slug' => $template->slug,
                'questions' => count($questions),
            ], $actor);

            return $template->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTemplate(SurveyTemplate $template, array $data, ?User $actor = null): SurveyTemplate
    {
        $before = ['name' => $template->name, 'is_active' => $template->is_active];
        $template->update($data);

        $this->audit->log($template->refresh(), 'survey.template_updated', $before, [
            'name' => $template->name,
            'is_active' => $template->is_active,
        ], $actor);

        return $template->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    public function setQuestions(SurveyTemplate $template, array $questions, ?User $actor = null): SurveyTemplate
    {
        return DB::transaction(function () use ($template, $questions, $actor): SurveyTemplate {
            $this->syncQuestions($template, $questions);

            $this->audit->log($template->refresh(), 'survey.questions_set', null, [
                'questions' => count($questions),
            ], $actor);

            return $template->refresh();
        });
    }

    /**
     * @throws ValidationException while campaigns run from the template
     */
    public function deleteTemplate(SurveyTemplate $template, ?User $actor = null): void
    {
        if ($template->campaigns()->exists()) {
            throw ValidationException::withMessages(['form' => 'That template has campaigns behind it — deactivate it instead of deleting history.']);
        }

        $this->audit->log($template, 'survey.template_deleted', [
            'slug' => $template->slug,
        ], null, $actor);

        $template->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function syncQuestions(SurveyTemplate $template, array $questions): void
    {
        $template->questions()->delete();

        foreach (array_values($questions) as $index => $question) {
            $template->questions()->create([
                'text' => (string) ($question['text'] ?? ''),
                'type' => $question['type'] ?? 'scale',
                'options' => $question['options'] ?? null,
                'is_required' => (bool) ($question['is_required'] ?? false),
                'min' => $question['min'] ?? null,
                'max' => $question['max'] ?? null,
                'sequence' => $question['sequence'] ?? ($index + 1) * 10,
            ]);
        }
    }
}
