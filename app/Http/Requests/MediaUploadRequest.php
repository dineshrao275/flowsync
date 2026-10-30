<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MediaUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Raster images only: SVGs can carry scripts and would execute
            // in the site origin, so logos ship as PNG/WebP.
            'file' => ['required', 'file', 'mimes:jpeg,png,gif,webp', 'max:5120'],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }
}
