<?php

namespace App\Policies\Hrms\Survey;

use App\Models\Hrms\Survey\SurveyTemplate;
use App\Models\User;

/**
 * Survey/HRMS — who may read or change a questionnaire.
 *
 * Reads take view (or manage); every mutation takes manage alone. Who may
 * *answer* is a different question answered elsewhere (campaign policy,
 * audience-resolved) — a template is never answered, only read and built.
 */
class SurveyTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, SurveyTemplate $template): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.engagement.manage');
    }

    public function update(User $user, SurveyTemplate $template): bool
    {
        return $user->hasPermission('hrms.engagement.manage');
    }

    public function delete(User $user, SurveyTemplate $template): bool
    {
        return $user->hasPermission('hrms.engagement.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.engagement.view')
            || $user->hasPermission('hrms.engagement.manage');
    }
}
