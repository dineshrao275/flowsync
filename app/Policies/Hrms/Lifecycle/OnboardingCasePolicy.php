<?php

namespace App\Policies\Hrms\Lifecycle;

/**
 * Lifecycle/HRMS — marker binding OnboardingCase to OnboardingPolicy.
 *
 * Exists purely so Laravel’s convention-based discovery finds the shared
 * base: no rules here, or the three models’ answers drift apart silently.
 */
class OnboardingCasePolicy extends OnboardingPolicy {}
