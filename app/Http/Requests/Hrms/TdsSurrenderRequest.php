<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Statutory/HRMS — depositing a quarter's shortfall.
 *
 * The project travels in the URL; the challan reference is the whole body.
 * An empty challan is not a deposit — the service refuses it by name, so
 * this requires presence and the service requires content.
 */
class TdsSurrenderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'challan_ref' => ['required', 'string', 'max:100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
