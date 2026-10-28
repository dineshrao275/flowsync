<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Locales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        // Only completed dictionaries are persistable: a half-translated
        // locale stays out of the product until review signs it off.
        return [
            'locale' => ['required', 'string', Rule::in(array_keys(Locales::completed()))],
        ];
    }
}
