<?php

namespace App\Policies\Hrms\Org;

/**
 * Org/HRMS — who may read and write a designation.
 *
 * A named subclass with no rules of its own, and that is the point: Laravel
 * resolves a model's policy by convention
 * (`App\Models\Hrms\Org\Designation` → this file), so the marker is what makes
 * `authorize('view', $designation)` work. The answers all live in
 * {@see OrgRecordPolicy}, and repeating them here would be three more places
 * for a permission check to drift.
 */
class DesignationPolicy extends OrgRecordPolicy {}
