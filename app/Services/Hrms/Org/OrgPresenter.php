<?php

namespace App\Services\Hrms\Org;

use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Org\Location;

/**
 * Org/HRMS — how an org record reaches the client.
 *
 * Separate from the services because the shape of a response and the rules
 * that produced the row are different things that change for different reasons:
 * a new column is a response change, a new validation rule is a service change,
 * and folding the first into the second means every later column arrives with
 * a question about which layer owns it.
 *
 * The nested tree is deliberately *not* presented here. {@see DepartmentChart}
 * already returns it with depth and both headcounts, computed in two queries;
 * re-shaping it here would mean walking the tree a second time to produce a
 * payload that is already correct.
 */
class OrgPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function department(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'slug' => $department->slug,
            'code' => $department->code,
            'parent_id' => $department->parent_id,
            'description' => $department->description,
            'is_active' => (bool) $department->is_active,
            'position' => (int) $department->position,
            'head_employee_id' => $department->head_employee_id,
            // The head as a small object rather than an id, so the tree column
            // can render a name without a second request per row.
            'head' => $department->head === null ? null : [
                'id' => $department->head->id,
                'name' => $department->head->name,
            ],
            'employees_count' => (int) ($department->employees_count ?? 0),
            'children_count' => (int) ($department->children_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function designation(Designation $designation): array
    {
        return [
            'id' => $designation->id,
            'name' => $designation->name,
            'slug' => $designation->slug,
            'code' => $designation->code,
            // A level is a band, and bands are what a payslip prints, so it is
            // a number here rather than a label the client has to re-derive.
            'level' => $designation->level === null ? null : (int) $designation->level,
            'department_id' => $designation->department_id,
            'is_active' => (bool) $designation->is_active,
            'position' => (int) $designation->position,
            'employees_count' => (int) ($designation->employees_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function location(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'slug' => $location->slug,
            'address_line1' => $location->address_line1,
            'address_line2' => $location->address_line2,
            'city' => $location->city,
            'state' => $location->state,
            'postal_code' => $location->postal_code,
            'country' => $location->country,
            'timezone' => $location->timezone,
            'is_active' => (bool) $location->is_active,
            'position' => (int) $location->position,
            // Reported as a *number*, not the raw decimal string: the database
            // round-trips these exactly and a JSON consumer should not have to
            // know that `19.0760000` and 19.076 are the same latitude.
            'geo_lat' => $location->geo_lat === null ? null : (float) $location->geo_lat,
            'geo_lng' => $location->geo_lng === null ? null : (float) $location->geo_lng,
            'geo_radius_m' => $location->geo_radius_m === null ? null : (int) $location->geo_radius_m,
            // The *effective* fence, not the stored flag: the two differ by
            // design, and a client drawing a map has to agree with attendance.
            'is_geo_fenced' => $location->isGeoFenced(),
            'employees_count' => (int) ($location->employees_count ?? 0),
        ];
    }
}
