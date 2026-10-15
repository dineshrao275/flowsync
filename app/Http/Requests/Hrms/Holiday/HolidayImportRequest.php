<?php

namespace App\Http\Requests\Hrms\Holiday;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Holiday/HRMS — a CSV or iCalendar upload for one calendar (preview or
 * commit). The extension decides the reader, so it is validated explicitly
 * rather than trusting a guessed MIME type for `.ics`.
 */
class HolidayImportRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv,txt,ics', 'max:1024'],
            'skip_invalid' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
