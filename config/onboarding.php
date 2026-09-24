<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant onboarding
    |--------------------------------------------------------------------------
    |
    | Optional self-service onboarding for new tenants. When `enabled`, the
    | public `POST /api/register` endpoint is live and self-registered tenants
    | must walk the wizard steps below before their domain routes (workspaces,
    | projects, tasks, dashboards, reports, …) are un-gated by
    | EnsureOnboardingComplete. Tenant admins/seeders bypass the wizard entirely
    | (a tenant that never started onboarding is treated as complete).
    |
    */

    'enabled' => (bool) env('ONBOARDING_ENABLED', false),

    'trial_days' => (int) env('ONBOARDING_TRIAL_DAYS', 14),

    'steps' => [
        'business' => [
            'title' => 'Business details',
            'description' => 'Tell us a little about your company and where it operates.',
            'required' => true,
        ],
        'admin' => [
            'title' => 'Admin & team',
            'description' => "You're the workspace administrator — invite teammates whenever you're ready.",
            'required' => true,
        ],
        'subscription' => [
            'title' => 'Choose a plan',
            'description' => 'Pick the plan that fits your team. You can change it any time.',
            'required' => true,
        ],
        'configuration' => [
            'title' => 'Workspace setup',
            'description' => 'Organize work into workspaces and projects.',
            'required' => false,
        ],
        'verification' => [
            'title' => 'Verification',
            'description' => 'Confirm your details are correct.',
            'required' => false,
        ],
        'completion' => [
            'title' => "You're ready",
            'description' => 'Finish setup and start using FlowSync.',
            'required' => true,
        ],
    ],
];
