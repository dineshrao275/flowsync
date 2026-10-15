<?php

namespace App\Services\Hrms\Performance;

use App\Enums\Hrms\FeedbackRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\FeedbackRequest;
use App\Models\Hrms\Performance\FeedbackResponse;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Performance/HRMS — answering feedback and reading it back.
 *
 * `respond()` is the only writer: submit (or re-submit, which edits the
 * row rather than stuffing a second ballot) or decline, with the request
 * following the answer. `presentRequest()` is the only reader of response
 * content, because anonymity is a display rule with four tiers — full for
 * managers and talent managers, masked names for `reviewer` cycles,
 * aggregates for `peer` cycles — and two call sites computing it would
 * disagree the moment one of them changed.
 */
class FeedbackService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Answer an ask: submit a perspective or decline it. Re-submitting
     * edits the row; the request follows the latest answer either way.
     *
     * @param  array{action: string, rating?: int|null, body?: string|null}  $data
     *
     * @throws ValidationException on a decided ask or a rating-less submit
     */
    public function respond(FeedbackRequest $request, array $data, User $actor): FeedbackResponse|FeedbackRequest
    {
        if ($request->status !== FeedbackRequestStatus::Pending) {
            throw ValidationException::withMessages(['form' => 'That ask is already answered.']);
        }

        if (($data['action'] ?? null) === 'decline') {
            return DB::transaction(function () use ($request, $actor): FeedbackRequest {
                $request->update(['status' => FeedbackRequestStatus::Declined]);

                $this->audit->log($request->refresh(), 'performance.feedback_declined', null, [
                    'to_employee_id' => $request->to_employee_id,
                ], $actor);

                return $request->refresh();
            });
        }

        if (! isset($data['rating']) || (int) $data['rating'] < 1 || (int) $data['rating'] > 5) {
            throw ValidationException::withMessages(['rating' => 'A submitted perspective rates 1 to 5.']);
        }

        return DB::transaction(function () use ($request, $data, $actor): FeedbackResponse {
            // The request names its reviewer; the policy guarantees the
            // actor is them, so the row files under the ask — never under
            // whoever happens to hold the session.
            $response = FeedbackResponse::query()->firstOrNew([
                'feedback_request_id' => $request->id,
                'from_employee_id' => $request->from_employee_id,
            ]);

            $response->fill([
                'rating' => (int) $data['rating'],
                'body' => $data['body'] ?? null,
                'submitted_at' => now(),
            ]);
            $response->save();

            $request->update(['status' => FeedbackRequestStatus::Submitted]);

            $this->audit->log($request->refresh(), 'performance.feedback_submitted', null, [
                'to_employee_id' => $request->to_employee_id,
            ], $actor);

            return $response->refresh();
        });
    }

    /**
     * One ask with its answers, shaped for the viewer: managers and talent
     * managers read everything; everyone else reads through the cycle's
     * anonymity — names masked for `reviewer`, counts and averages for
     * `peer`, everything for `none`. A reviewer's own row is always full
     * to them; nobody else's row ever leaks through it.
     *
     * @return array<string, mixed>
     */
    public function presentRequest(FeedbackRequest $request, User $viewer): array
    {
        $request->loadMissing(['from:id,employee_code,name,user_id', 'to:id,employee_code,name,user_id', 'responses.from:id,employee_code,name', 'cycle:id,anonymity']);

        $tier = $this->tier($request, $viewer);

        return [
            'id' => $request->id,
            'cycle_id' => $request->cycle_id,
            'from' => $this->person($request->from),
            'to' => $this->person($request->to),
            'relation' => $request->relation->value,
            'status' => $request->status->value,
            'due_date' => $request->due_date?->toDateString(),
            'responses' => $this->presentResponses($request, $viewer, $tier),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResponses(FeedbackRequest $request, User $viewer, string $tier): array
    {
        $responses = $request->responses;

        if ($tier === 'aggregate') {
            $ratings = $responses->pluck('rating')->filter();

            return [
                'count' => $responses->count(),
                'average_rating' => $ratings->isEmpty() ? null : round($ratings->avg(), 2),
                'rows' => [],
            ];
        }

        return [
            'count' => $responses->count(),
            'average_rating' => null,
            'rows' => $responses->map(fn (FeedbackResponse $response): array => [
                'id' => $response->id,
                'from' => $tier === 'masked' && ! $this->isOwnResponse($response, $viewer)
                    ? ['id' => null, 'name' => 'A reviewer']
                    : $this->person($response->from),
                'rating' => $response->rating,
                'body' => $response->body,
                'submitted_at' => $response->submitted_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Full, masked-names, or aggregate: managers and talent managers read
     * everything; the reviewee reads through the cycle's anonymity; a
     * reviewer reads through it too (their own row excepted); view-permission
     * bystanders aggregate, because names and bodies of peers are exactly
     * what anonymity withholds.
     */
    private function tier(FeedbackRequest $request, User $viewer): string
    {
        if ($this->manages($viewer, $request)) {
            return 'full';
        }

        $anonymity = $request->cycle?->anonymity ?? 'peer';

        if ($anonymity === 'none') {
            return 'full';
        }

        return $anonymity === 'reviewer' ? 'masked' : 'aggregate';
    }

    private function manages(User $viewer, FeedbackRequest $request): bool
    {
        if ($viewer->hasPermission('hrms.talent.manage')
            || $viewer->hasPermission('hrms.performance.manage')) {
            return true;
        }

        $to = $request->to ?? Employee::find($request->to_employee_id);

        return $to !== null
            && $to->manager !== null
            && $to->manager->user_id !== null
            && (int) $to->manager->user_id === (int) $viewer->id;
    }

    private function isOwnResponse(FeedbackResponse $response, User $viewer): bool
    {
        $from = $response->from ?? Employee::find($response->from_employee_id);

        return $from !== null
            && $from->user_id !== null
            && (int) $from->user_id === (int) $viewer->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function person(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->displayName(),
        ];
    }
}
