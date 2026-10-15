<?php

namespace App\Policies\Hrms\Lifecycle;

/**
 * Lifecycle/HRMS — marker binding OffboardingCaseTask to OffboardingPolicy.
 *
 * Exists purely so Laravel’s convention-based discovery finds the shared
 * base: no rules here, or the two models’ answers drift apart silently.
 */
class OffboardingCaseTaskPolicy extends OffboardingPolicy {}
