<?php

namespace App\Http\Controllers\Hrms\Survey;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\SurveyCampaignRequest;
use App\Http\Requests\Hrms\SurveyRespondRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Survey\SurveyCampaign;
use App\Models\Hrms\Survey\SurveyTemplate;
use App\Models\User;
use App\Services\Hrms\Survey\EngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Survey/HRMS — campaigns from scheduling to answers over HTTP.
 *
 * Thin: it authorizes (listing and reading on view or invitation,
 * scheduling and every state move on manage, answering on invitation
 * alone), hands the payload to the engagement service, and shapes the
 * envelope. Fingerprints derive server-side from the observed IP and
 * user agent — the request carries answers, never identity.
 */
class SurveyCampaignController extends Controller
{
    public function __construct(private readonly EngagementService $engagement) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SurveyCampaign::class);

        return response()->json([
            'campaigns' => SurveyCampaign::query()->with(['template:id,name,is_anonymous'])->orderByDesc('id')
                ->get()->map(fn (SurveyCampaign $campaign): array => $this->present($campaign))->all(),
        ]);
    }

    public function show(Request $request, SurveyCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        return response()->json(['campaign' => $this->present($campaign, $request->user())]);
    }

    public function store(SurveyCampaignRequest $request): JsonResponse
    {
        $this->authorize('create', SurveyCampaign::class);

        $data = $request->validated();
        $template = SurveyTemplate::findOrFail((int) $data['template_id']);
        unset($data['template_id']);

        $campaign = $this->engagement->schedule($template, $data, $request->user());

        return response()->json([
            'message' => 'Campaign scheduled.',
            'campaign' => $this->present($campaign),
        ], Response::HTTP_CREATED);
    }

    public function open(Request $request, SurveyCampaign $campaign): JsonResponse
    {
        $this->authorize('transition', $campaign);

        return response()->json([
            'message' => 'Campaign opened.',
            'campaign' => $this->present($this->engagement->open($campaign, $request->user())),
        ]);
    }

    public function close(Request $request, SurveyCampaign $campaign): JsonResponse
    {
        $this->authorize('transition', $campaign);

        return response()->json([
            'message' => 'Campaign closed.',
            'campaign' => $this->present($this->engagement->close($campaign, $request->user())),
        ]);
    }

    public function invite(Request $request, SurveyCampaign $campaign): JsonResponse
    {
        $this->authorize('transition', $campaign);

        return response()->json([
            'message' => 'Audience invited.',
            'invited' => $this->engagement->invite($campaign, $request->user()),
        ]);
    }

    public function results(Request $request, SurveyCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        return response()->json($this->engagement->results($campaign));
    }

    public function respond(SurveyRespondRequest $request, SurveyCampaign $campaign): JsonResponse
    {
        $this->authorize('respond', $campaign);

        $employee = Employee::where('user_id', $request->user()->id)->first();

        $response = $this->engagement->respond(
            $campaign,
            $employee,
            $request->validated()['answers'],
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
            $request->user(),
        );

        return response()->json([
            'message' => 'Answer recorded.',
            'response' => ['id' => $response->id],
        ], Response::HTTP_CREATED);
    }

    /**
     * The caller's open invitations with answer state: invited campaigns
     * plus whether this login already answered each. Self-scoped like
     * notifications — no employment record means an empty list, because a
     * login without a record was invited to nothing.
     */
    public function mine(Request $request): JsonResponse
    {
        $campaigns = SurveyCampaign::query()->where('status', 'open')
            ->with(['template:id,name,is_anonymous,audience_scope,audience_meta'])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (SurveyCampaign $campaign): bool => $this->engagement->isInvited($campaign, $request->user()));

        return response()->json([
            'campaigns' => $campaigns->map(fn (SurveyCampaign $campaign): array => [
                ...$this->present($campaign),
                'answered' => $this->answered($campaign, $request->user(), $request),
            ])->values()->all(),
        ]);
    }

    /**
     * One invited campaign with its questions for answering: the payload
     * carries no answers and no aggregates — this is the blank form, and
     * the results endpoint is the scored one.
     */
    public function mySurvey(Request $request, SurveyCampaign $campaign): JsonResponse
    {
        $this->authorize('respond', $campaign);

        $campaign->load(['template.questions' => fn ($query) => $query->orderBy('sequence')]);

        return response()->json([
            'campaign' => $this->present($campaign),
            'questions' => $campaign->template->questions->map(fn ($question): array => [
                'id' => $question->id,
                'text' => $question->text,
                'type' => $question->type->value,
                'options' => $question->options,
                'is_required' => $question->is_required,
                'min' => $question->min === null ? null : (string) $question->min,
                'max' => $question->max === null ? null : (string) $question->max,
                'sequence' => $question->sequence,
            ])->all(),
            'answered' => $this->answered($campaign, $request->user(), $request),
        ]);
    }

    private function answered(SurveyCampaign $campaign, User $user, Request $request): bool
    {
        $employeeId = Employee::where('user_id', $user->id)->value('id');

        if ($employeeId === null) {
            return $campaign->responses()
                ->whereNull('employee_id')
                ->where('respondent_key', $this->fingerprintFor($campaign, $request))
                ->exists();
        }

        return $campaign->responses()->where('employee_id', $employeeId)->exists()
            || $campaign->responses()
                ->whereNull('employee_id')
                ->where('respondent_key', $this->fingerprintFor($campaign, $request))
                ->exists();
    }

    private function fingerprintFor(SurveyCampaign $campaign, Request $request): string
    {
        return hash('md5', $campaign->id.'.'.$request->ip().'.'.$request->userAgent());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SurveyCampaign $campaign): array
    {
        $campaign->loadMissing('template:id,name,is_anonymous');

        return [
            'id' => $campaign->id,
            'template_id' => $campaign->template_id,
            'template' => $campaign->template ? [
                'id' => $campaign->template->id,
                'name' => $campaign->template->name,
                'is_anonymous' => $campaign->template->is_anonymous,
            ] : null,
            'name' => $campaign->name,
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
            'status' => $campaign->status->value,
            'anonymity_threshold' => $campaign->anonymity_threshold,
            'notify_on_publish' => $campaign->notify_on_publish,
        ];
    }
}
