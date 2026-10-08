<?php

namespace App\Policies\Hrms\Lifecycle;

/**
 * Lifecycle/HRMS — marker binding OnboardingTemplate to OnboardingPolicy.
 *
 * Exists purely so Laravel’s convention-based discovery finds the shared
 * base: no rules here, or the three models’ answers drift apart silently.
 */
class OnboardingTemplatePolicy extends OnboardingPolicy {}
