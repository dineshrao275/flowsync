<?php

namespace App\Policies\Hrms\Org;

/**
 * Org/HRMS — who may read and write a location.
 *
 * A named subclass with no rules of its own, and that is the point: Laravel
 * resolves a model's policy by convention
 * (`App\Models\Hrms\Org\Location` → this file), so the marker is what makes
 * `authorize('view', $location)` work. The answers all live in
 * {@see OrgRecordPolicy}, and repeating them here would be three more places
 * for a permission check to drift.
 */
class LocationPolicy extends OrgRecordPolicy {}
