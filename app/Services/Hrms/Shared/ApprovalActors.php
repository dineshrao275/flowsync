<?php

namespace App\Services\Hrms\Shared;

use App\Models\Hrms\Shared\Approval;
use App\Models\Hrms\Shared\ApprovalStep;
use App\Models\User;

/**
 * Shared/HRMS — who may act on which pending step of an approval, and how.
 *
 * Three routes, tried in order of how ordinary they are: the step's own
 * approver (`direct`), a delegate of that approver inside an active window
 * (`delegate`), and the explicit `hrms.approvals.override` permission
 * (`override`, R11 — reason mandatory, never on your own request). There is
 * deliberately no silent administrator bypass.
 */
class ApprovalActors
{
    public const DIRECT = 'direct';

    public const DELEGATE = 'delegate';

    public const OVERRIDE = 'override';

    public function __construct(private readonly ApprovalDelegations $delegations) {}

    /**
     * The pending step of the current stage this user can decide, and the route.
     *
     * @return array{step: ApprovalStep, via: string, for: int|null}|null
     */
    public function resolve(Approval $approval, User $user): ?array
    {
        $steps = $approval->currentStageSteps();

        if ($steps->isEmpty()) {
            return null;
        }

        foreach ($steps as $step) {
            if ($step->canBeActedBy($user)) {
                return ['step' => $step, 'via' => self::DIRECT, 'for' => null];
            }
        }

        $isRequester = $this->isRequester($approval, $user);

        if (! $isRequester) {
            foreach ($steps as $step) {
                $for = $this->delegatorFor($step, $approval, $user);

                if ($for !== null) {
                    return ['step' => $step, 'via' => self::DELEGATE, 'for' => $for];
                }
            }
        }

        if ($this->mayOverride($approval, $user)) {
            return ['step' => $steps->first(), 'via' => self::OVERRIDE, 'for' => null];
        }

        return null;
    }

    /**
     * R11: needs its own permission, never available on a request the user
     * made themselves.
     */
    public function mayOverride(Approval $approval, User $user): bool
    {
        return $approval->isOpen()
            && ! $this->isRequester($approval, $user)
            && $user->hasPermission(ApprovalService::OVERRIDE_PERMISSION);
    }

    public function isRequester(Approval $approval, User $actor): bool
    {
        return $approval->requested_by_user_id !== null
            && $approval->requested_by_user_id === $actor->id;
    }

    /** The delegator whose seat this user holds on the step, if any. */
    private function delegatorFor(ApprovalStep $step, Approval $approval, User $user): ?int
    {
        $ids = $this->delegations->delegatorIds($user, $approval->domain);

        if ($ids === []) {
            return null;
        }

        foreach (User::query()->whereIn('id', $ids)->get() as $delegator) {
            if ($step->canBeActedBy($delegator)) {
                return (int) $delegator->id;
            }
        }

        return null;
    }
}
