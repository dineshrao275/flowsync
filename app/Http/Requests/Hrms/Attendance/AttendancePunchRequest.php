<?php

namespace App\Http\Requests\Hrms\Attendance;

use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchKind;
use App\Enums\Hrms\PunchSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attendance/HRMS — one remote clock press.
 *
 * Direction is required and closed: a punch goes in or out, and anything
 * else is a 422 here rather than a 500 in the pairing code. Coordinates are
 * optional — a punch with none simply skips the geofence leg — but when
 * present they must be numbers, because a “lat” of “somewhere downtown”
 * would sail through a string rule and die inside the haversine.
 */
class AttendancePunchRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(PunchDirection::class)],
            'kind' => ['sometimes', Rule::enum(PunchKind::class)],
            'source' => ['sometimes', Rule::enum(PunchSource::class)],
            'punch_at' => ['sometimes', 'nullable', 'date'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function authorize(): bool
    {
        // Self-scoped by design: the controller resolves the employment
        // record from the authenticated user, so there is nothing to
        // pre-authorize here and no login may punch for another.
        return true;
    }
}
