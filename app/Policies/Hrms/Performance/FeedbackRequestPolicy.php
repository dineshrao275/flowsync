<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\FeedbackRequest;
use App\Models\User;

/**
 * Performance/HRMS — who may see or answer a feedback ask.
 *
 * Reading takes the reviewer, the reviewee, or the view permission — what
 * each of them may *see* is the presenter's call, because anonymity is a
 * display rule, not an access rule. Answering takes the requested reviewer
 * alone: nobody responds on another person's behalf, not even manage.
 */
class FeedbackRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists() || $this->canView($user);
    }

    public function view(User $user, FeedbackRequest $request): bool
    {
        return $this->isReviewer($user, $request)
            || $this->isReviewee($user, $request)
            || $this->isManagerOf($user, $request)
            || $this->canView($user);
    }

    public function respond(User $user, FeedbackRequest $request): bool
    {
        return $this->isReviewer($user, $request);
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.performance.view')
            || $user->hasPermission('hrms.performance.manage')
            || $user->hasPermission('hrms.talent.manage');
    }

    private function isReviewer(User $user, FeedbackRequest $request): bool
    {
        $from = $request->from ?? Employee::find($request->from_employee_id);

        return $from !== null
            && $from->user_id !== null
            && (int) $from->user_id === (int) $user->id;
    }

    private function isReviewee(User $user, FeedbackRequest $request): bool
    {
        $to = $request->to ?? Employee::find($request->to_employee_id);

        return $to !== null
            && $to->user_id !== null
            && (int) $to->user_id === (int) $user->id;
    }

    private function isManagerOf(User $user, FeedbackRequest $request): bool
    {
        $to = $request->to ?? Employee::find($request->to_employee_id);

        return $to !== null
            && $to->manager !== null
            && $to->manager->user_id !== null
            && (int) $to->manager->user_id === (int) $user->id;
    }
}
