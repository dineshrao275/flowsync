<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Talent/HRMS — calibrating a review.
 *
 * One human-entered 1–5 rating replacing another: there is no averaging,
 * no weighting and no composite field, and none may be smuggled through
 * here. The controller authorizes (policy), the service judges the stage.
 */
class TalentCalibrateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.min' => 'A calibrated rating is 1–5.',
            'rating.max' => 'A calibrated rating is 1–5.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
