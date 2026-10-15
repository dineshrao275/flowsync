<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ThemeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            ...array_fill_keys(array_keys(config('theme.defaults')), [
                'required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/',
            ]),
            // Optional so clients that only send colors keep working; the
            // controller falls back to the stored mode, then the default.
            'mode' => ['sometimes', 'string', 'in:'.implode(',', config('theme.modes'))],
        ];
    }
}
