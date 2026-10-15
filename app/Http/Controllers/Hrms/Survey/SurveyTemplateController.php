<?php

namespace App\Http\Controllers\Hrms\Survey;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\SurveyQuestionsRequest;
use App\Http\Requests\Hrms\SurveyTemplateRequest;
use App\Models\Hrms\Survey\SurveyTemplate;
use App\Services\Hrms\Survey\SurveyTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Survey/HRMS — questionnaires over HTTP.
 *
 * Thin: it authorizes against the template policy (reads on view, every
 * mutation on manage), hands the payload to the template service, and
 * shapes the envelope. Questions ride creation and sync wholesale
 * afterwards — never one-by-one, so a payload cannot smuggle a question
 * onto another template.
 */
class SurveyTemplateController extends Controller
{
    public function __construct(private readonly SurveyTemplateService $templates) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SurveyTemplate::class);

        return response()->json([
            'templates' => SurveyTemplate::query()->with('questions')->orderBy('name')
                ->get()->map(fn (SurveyTemplate $template): array => $this->present($template))->all(),
        ]);
    }

    public function show(SurveyTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        return response()->json(['template' => $this->present($template->load('questions'))]);
    }

    public function store(SurveyTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', SurveyTemplate::class);

        $data = $request->validated();
        $questions = $data['questions'] ?? [];
        unset($data['questions']);

        $template = $this->templates->createTemplate($data, $questions, $request->user());

        return response()->json([
            'message' => 'Template created.',
            'template' => $this->present($template->load('questions')),
        ], Response::HTTP_CREATED);
    }

    public function update(SurveyTemplateRequest $request, SurveyTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $data = $request->validated();
        unset($data['questions']);

        $updated = $this->templates->updateTemplate($template, $data, $request->user());

        return response()->json([
            'message' => 'Template updated.',
            'template' => $this->present($updated->load('questions')),
        ]);
    }

    public function destroy(Request $request, SurveyTemplate $template): JsonResponse
    {
        $this->authorize('delete', $template);

        $this->templates->deleteTemplate($template, $request->user());

        return response()->json(['message' => 'Template deleted.']);
    }

    public function setQuestions(SurveyQuestionsRequest $request, SurveyTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $updated = $this->templates->setQuestions($template, $request->validated()['questions'], $request->user());

        return response()->json([
            'message' => 'Questions updated.',
            'template' => $this->present($updated->load('questions')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SurveyTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'type' => $template->type->value,
            'is_anonymous' => $template->is_anonymous,
            'is_active' => $template->is_active,
            'frequency' => $template->frequency->value,
            'audience_scope' => $template->audience_scope->value,
            'audience_meta' => $template->audience_meta,
            'settings' => $template->settings,
            'questions' => $template->questions->map(fn ($question): array => [
                'id' => $question->id,
                'text' => $question->text,
                'type' => $question->type->value,
                'options' => $question->options,
                'is_required' => $question->is_required,
                'min' => $question->min === null ? null : (string) $question->min,
                'max' => $question->max === null ? null : (string) $question->max,
                'sequence' => $question->sequence,
            ])->all(),
        ];
    }
}
