<?php

namespace App\Services\Hrms\Org;

use App\Models\Hrms\Org\Location;
use Illuminate\Validation\ValidationException;

/**
 * Org/HRMS — location writes.
 *
 * The only one of the three catalogs that carries a rule the others do not: a
 * geofence is either complete or it is not. Half a fence is worse than none,
 * because it looks configured.
 */
class LocationService
{
    public function __construct(private readonly OrgNaming $naming) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Location
    {
        $location = new Location;
        $this->fill($location, $data);

        return $location->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Location $location, array $data): Location
    {
        $this->fill($location, $data);

        return $location->refresh();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $ids = $this->naming->order($orderedIds, Location::query()->pluck('id')->all());
        $positions = array_flip($ids);

        $this->naming->renumber(
            Location::whereIn('id', $ids)->get()
                ->sortBy(fn (Location $location): int => $positions[$location->id])
                ->all()
        );
    }

    /**
     * @throws ValidationException 422 while employees are attached to the site
     */
    public function delete(Location $location): void
    {
        if ($location->employees()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'Employees are still assigned to this location. Move them, or deactivate the location instead.',
            ]);
        }

        $location->delete();
    }

    public function deactivate(Location $location): Location
    {
        $location->is_active = false;
        $location->save();

        return $location;
    }

    /**
     * Apply a payload, leaving anything it does not mention alone.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Location $location, array $data): void
    {
        if (array_key_exists('name', $data) && $data['name'] !== $location->name) {
            $location->name = $data['name'];
            $location->slug = $this->naming->uniqueSlug(Location::class, $data['name'], $location->id);
        }

        foreach ([
            'address_line1', 'address_line2', 'city', 'state', 'postal_code',
            'country', 'timezone', 'is_active',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $location->{$field} = $data[$field];
            }
        }

        $this->applyGeofence($location, $data);

        $location->save();
    }

    /**
     * A fence is three parts that have to arrive together, and the flag is
     * derived from them rather than accepted.
     *
     * `is_geo_fenced` is not persisted as sent. A client that ticks the box
     * without supplying a centre and a radius gets a location that reports "not
     * geofenced" instead of a flag that quietly claims a fence exists — and
     * attendance in P8 asks `isGeoFenced()` rather than trusting the column, so
     * it gets the truth either way.
     *
     * Coordinates without a fence are kept, not cleared: a site is useful on a
     * map long before anybody configures a geofence for it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException 422
     */
    private function applyGeofence(Location $location, array $data): void
    {
        foreach (['geo_lat', 'geo_lng', 'geo_radius_m'] as $field) {
            if (array_key_exists($field, $data)) {
                $location->{$field} = $data[$field];
            }
        }

        $wantsFence = array_key_exists('is_geo_fenced', $data)
            ? (bool) $data['is_geo_fenced']
            : $location->is_geo_fenced;

        if ($wantsFence && ! $location->hasFenceGeometry()) {
            throw ValidationException::withMessages([
                'form' => 'A geofence needs a latitude, a longitude and a radius greater than zero. Clear the geofence box to save the location without one.',
            ]);
        }

        // Derived from the intent *and* the geometry, never from the request
        // alone. Asking isGeoFenced() here would be circular — it reads this
        // very column.
        $location->is_geo_fenced = $wantsFence && $location->hasFenceGeometry();
    }
}
