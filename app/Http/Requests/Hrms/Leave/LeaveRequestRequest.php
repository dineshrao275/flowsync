<?php

namespace App\Http\Requests\Hrms\Leave;

use App\Enums\Hrms\LeaveHalf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Leave/HRMS — filing or narrowly editing a leave ask.
 *
 * POST carries the whole ask; PUT carries only the non-balance fields. The
 * range, halves and type are `prohibited` on PUT by name (the P2.3
 * EmployeeUpdateRequest precedent): silently dropping them would answer 200
 * to a client that believes it moved its leave, while the balance still
 * prices the old range. Re-dating is cancel-and-refile.
 */
class LeaveRequestRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $updating = $this->isMethod('PUT');

        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'leave_type_id' => [
                $updating ? 'prohibited' : Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'integer',
                'exists:leave_types,id',
            ],
            'from_date' => [
                $updating ? 'prohibited' : Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'date',
            ],
            'to_date' => [
                $updating ? 'prohibited' : Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'date',
                'after_or_equal:from_date',
            ],
            'from_half' => [$updating ? 'prohibited' : 'sometimes', 'nullable', Rule::enum(LeaveHalf::class)],
            'to_half' => [$updating ? 'prohibited' : 'sometimes', 'nullable', Rule::enum(LeaveHalf::class)],
            'reason' => [Rule::requiredIf(fn (): bool => $this->isMethod('POST')), 'string', 'min:3', 'max:2000'],
            'contact_during_leave' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_id' => ['sometimes', 'nullable', 'integer', 'exists:employee_documents,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'leave_type_id.prohibited' => 'Re-dating or re-typing leave is cancel-and-refile; this endpoint edits notes only.',
            'from_date.prohibited' => 'Re-dating or re-typing leave is cancel-and-refile; this endpoint edits notes only.',
            'to_date.prohibited' => 'Re-dating or re-typing leave is cancel-and-refile; this endpoint edits notes only.',
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
