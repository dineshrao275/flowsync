<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class AttachmentStoreRequest extends FormRequest
{
    // SVG is deliberately absent: it is executable markup (stored XSS when
    // served inline), and nothing in the product needs vector uploads.
    private const ALLOWED_MIMES = [
        'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt',
        'pptx', 'txt', 'md', 'csv', 'zip', 'json',
    ];

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(self::ALLOWED_MIMES)->max(10 * 1024),
            ],
        ];
    }
}
