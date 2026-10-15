<?php

namespace App\Models\Hrms\Org;

use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Org\LocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Org/HRMS — a work site.
 *
 * Two kinds of thing in one table, which is the point: a name-only row is an
 * office people are attached to for reporting, and a row with coordinates is
 * one the attendance module can geofence.
 *
 * The address here is the *site's* address — the one a visitor is sent to and
 * the one printed on a payslip's tax declaration — and so it is **not** covered
 * by the `SensitiveFieldRedactor` masking that P2.4 applies to a person's home
 * address. A location's city and country are not personal data; a home address
 * is.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $timezone
 * @property string|null $geo_lat
 * @property string|null $geo_lng
 * @property int|null $geo_radius_m
 * @property bool $is_geo_fenced
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Employee> $employees
 */
class Location extends Model
{
    protected $table = 'locations';

    protected $fillable = [
        'name',
        'slug',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'timezone',
        'geo_lat',
        'geo_lng',
        'geo_radius_m',
        'is_geo_fenced',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            // Coordinates are decimal(10,7) in the database, kept as strings on
            // the model for the same reason they are not floats in the
            // database: 7 decimal places is ~1 cm and a float cannot hold most
            // of those values exactly. Presenting or comparing one as a float
            // reintroduces the error P3.1 avoided.
            'geo_lat' => 'decimal:7',
            'geo_lng' => 'decimal:7',
            'geo_radius_m' => 'integer',
            'is_geo_fenced' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'location_id');
    }

    /**
     * Whether the stored geometry can answer "is this person at the office".
     *
     * Three parts, all required. A row with a radius of 0 and no coordinates is
     * the dangerous case — it *looks* configured, and comparing against a null
     * centre either silently denies every remote punch or matches everything.
     */
    public function hasFenceGeometry(): bool
    {
        return $this->geo_lat !== null
            && $this->geo_lng !== null
            && $this->geo_radius_m !== null
            && (int) $this->geo_radius_m > 0;
    }

    /**
     * Whether the fence is switched *on* and actually usable.
     *
     * The flag alone is not the answer, which is the whole point: the write
     * path derives the flag from the geometry ({@see LocationService}),
     * so a client that ticks the box without a centre cannot leave a row that
     * claims a fence it does not have.
     */
    public function isGeoFenced(): bool
    {
        return $this->is_geo_fenced && $this->hasFenceGeometry();
    }

    /** @param  Builder<Location>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
