<?php

namespace App\Http\Requests\Hrms\Org;

use App\Services\Hrms\Org\LocationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Org/HRMS — creating or editing a location.
 *
 * The geofence rules are *shapes*, not a claim: the coordinates and radius are
 * validated here so a malformed one is a 422 on the field, and whether the
 * three of them together constitute a usable fence is decided by
 * {@see LocationService}, because only the service sees
 * the values merged with whatever the row already had.
 *
 * `slug` is server-allocated, like the other org records'.
 */
class LocationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'string',
                'max:255',
            ],

            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            // A two-letter code, because a location is a place and a place has a
            // country code — not a free-text country name that half the rows
            // spell differently.
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],

            'geo_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'geo_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'geo_radius_m' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'is_geo_fenced' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.size' => 'Use the two-letter country code, for example IN or US.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
