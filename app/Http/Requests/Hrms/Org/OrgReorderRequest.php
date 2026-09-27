<?php

namespace App\Http\Requests\Hrms\Org;

use App\Services\Hrms\Org\OrgNaming;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Org/HRMS — a drag-and-drop reorder.
 *
 * One request for all three reorder endpoints, because the payload is identical
 * and the *only* difference between them is which service renumbers the result.
 *
 * The ids are not checked for existence here. A reorder names the members of one
 * sibling list, and the intersection with that list is
 * {@see OrgNaming::order()}'s job — the service is the
 * only place that knows which list is being reordered, and validating "this id
 * exists" here would accept an id from a completely different parent.
 */
class OrgReorderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_map('intval', $this->validated()['ids']);
    }

    public function authorize(): bool
    {
        return true;
    }
}
