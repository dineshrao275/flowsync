<?php

namespace App\Services\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceIpRule;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Location;
use App\Models\Hrms\Shared\HrmsSetting;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Attendance/HRMS — is this punch where it is allowed to be.
 *
 * IP rules, then the geofence. What happens to an out-of-range punch is a
 * tenant choice (`remote_clock_in.out_of_range_action`): `flag` (the default)
 * records it with a reason for the reviewer — a mispinned fence or a stale
 * allow-list must not lock anyone out of recording that they worked — while
 * `block` refuses it outright with that same reason. Under `block`, a punch
 * from a fenced person that carries no coordinates is refused too (otherwise
 * leaving the coordinates out would bypass the fence), and with
 * `remote_clock_in.require_geofence` on, a missing position is out-of-range in
 * either mode.
 */
class PunchRangeCheck
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array{bool, string|null} flagged, reason
     *
     * @throws ValidationException under the `block` action when out of range
     */
    public function check(Employee $employee, array $meta): array
    {
        $reason = $this->reason($employee, $meta);

        if ($reason === null) {
            return [false, null];
        }

        if ($this->action() === 'block') {
            throw ValidationException::withMessages(['location' => $reason]);
        }

        return [true, $reason];
    }

    public function action(): string
    {
        $value = HrmsSetting::query()->find(HrmsSetting::SINGLETON_ID)?->setting('remote_clock_in.out_of_range_action');

        return $value === 'block' ? 'block' : 'flag';
    }

    /** @param  array<string, mixed>  $meta */
    private function reason(Employee $employee, array $meta): ?string
    {
        if (! empty($meta['ip']) && ($reason = $this->networkReason((string) $meta['ip'])) !== null) {
            return $reason;
        }

        $location = $employee->location_id !== null ? Location::find($employee->location_id) : null;

        if ($location === null || ! $location->isGeoFenced()) {
            return null;
        }

        if (! isset($meta['lat'], $meta['lng'])) {
            $setting = HrmsSetting::query()->find(HrmsSetting::SINGLETON_ID);
            $required = $this->action() === 'block' || (bool) $setting?->setting('remote_clock_in.require_geofence');

            // No coordinates cannot be placed: flagged only when the tenant
            // demanded a position, otherwise absence of evidence is not an offence.
            return $required ? "Location is required to clock in at {$location->name}." : null;
        }

        return $this->fenceReason($location, (float) $meta['lat'], (float) $meta['lng']);
    }

    /**
     * An empty active set means “no network policy”, not “deny everything”:
     * a tenant that never configured IP rules must not find clock-in broken
     * on Monday because of a feature they never switched on.
     */
    private function networkReason(string $ip): ?string
    {
        $rules = AttendanceIpRule::active()->pluck('cidr')->all();

        if ($rules === []) {
            return null;
        }

        foreach ($rules as $cidr) {
            if (IpUtils::checkIp($ip, $cidr)) {
                return null;
            }
        }

        return "IP {$ip} is outside the allowed networks.";
    }

    private function fenceReason(Location $location, float $lat, float $lng): ?string
    {
        $distance = $this->haversineMetres($lat, $lng, (float) $location->geo_lat, (float) $location->geo_lng);

        if ($distance <= (int) $location->geo_radius_m) {
            return null;
        }

        return sprintf('%.0f m outside %s’s %d m fence.', $distance, $location->name, $location->geo_radius_m);
    }

    private function haversineMetres(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $earth = 6371000;
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);

        $arc = sin($dLat / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(sqrt($arc));
    }
}
