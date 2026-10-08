<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Document/HRMS — the document-type catalogue query string.
 *
 * Read-only by design: types are seeded system rows a tenant may rename, and
 * there is no admin mutation endpoint in P13.3. The one thing this validates
 * is the picker’s own inputs — a bad `category` must 422 here, not reach an
 * enum cast and 500.
 */
class DocumentTypeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'category' => ['sometimes', 'nullable', Rule::enum(DocumentCategory::class)],
            'active' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Multipart and query strings deliver booleans as text; the `boolean`
     * rule only accepts real booleans, so “false” arrives here before it can
     * become validation’s problem.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('active')) {
            $value = $this->input('active');
            $filtered = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : $value;

            $this->merge(['active' => $filtered ?? $value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            fn (mixed $value) => $value !== null,
        );
    }

    public function authorize(): bool
    {
        // The route group already gates the surface on `hrms.view`; per-record
        // catalogue reads need no policy beyond that.
        return true;
    }
}
