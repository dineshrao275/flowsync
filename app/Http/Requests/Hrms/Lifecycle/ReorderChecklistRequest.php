<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifecycle/HRMS — reordering a template’s checklist.
 *
 * The whole id list, not a target index: the client and the server cannot
 * disagree about what “position 2” means, and the service intersects the
 * submitted ids with the real siblings — so a payload naming an id from
 * another template cannot drag it over.
 */
class ReorderChecklistRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
