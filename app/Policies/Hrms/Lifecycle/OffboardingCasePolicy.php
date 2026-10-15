<?php

namespace App\Policies\Hrms\Lifecycle;

/**
 * Lifecycle/HRMS — marker binding OffboardingCase to OffboardingPolicy.
 *
 * Exists purely so Laravel’s convention-based discovery finds the shared
 * base: no rules here, or the two models’ answers drift apart silently.
 */
class OffboardingCasePolicy extends OffboardingPolicy {}
