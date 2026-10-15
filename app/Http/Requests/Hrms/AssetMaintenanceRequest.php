<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asset/HRMS — opening a repair record.
 *
 * The asset travels in the URL; the work and its cost travel here. Money
 * is shape-checked, never priced — the record states what the shop
 * charged, and the service never computes from it.
 */
class AssetMaintenanceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(['repair', 'service', 'upgrade', 'inspection'])],
            'description' => ['required', 'string', 'max:2000'],
            'performed_by' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cost' => ['sometimes', 'nullable', 'numeric'],
            'performed_at' => ['required', 'date'],
            'next_due_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:performed_at'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
