<?php

namespace App\Policies\Hrms\Survey;

use App\Models\Hrms\Survey\SurveyCampaign;
use App\Models\User;
use App\Services\Hrms\Survey\EngagementService;

/**
 * Survey/HRMS — who may see, move, or answer a campaign.
 *
 * Listing and reading take view (or manage); scheduling and every state
 * move take manage alone; answering takes an invitation — authenticated
 * only is necessary but not sufficient, and the audience rule lives in
 * the service so this policy and the HTTP surface resolve the same
 * invitees. Results read through the same view gate as the campaign;
 * the threshold itself (not this policy) withholds the rows.
 */
class SurveyCampaignPolicy
{
    public function __construct(private readonly EngagementService $engagement) {}

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, SurveyCampaign $campaign): bool
    {
        return $this->canView($user) || $this->engagement->isInvited($campaign, $user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.engagement.manage');
    }

    public function transition(User $user, SurveyCampaign $campaign): bool
    {
        return $user->hasPermission('hrms.engagement.manage');
    }

    public function respond(User $user, SurveyCampaign $campaign): bool
    {
        return $this->engagement->isInvited($campaign, $user);
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.engagement.view')
            || $user->hasPermission('hrms.engagement.manage');
    }
}
