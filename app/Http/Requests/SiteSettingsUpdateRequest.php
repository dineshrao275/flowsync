<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\SiteHref;
use Illuminate\Foundation\Http\FormRequest;

class SiteSettingsUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tagline' => ['nullable', 'string', 'max:500'],
            'logo_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'nav' => ['nullable', 'array', 'max:12'],
            'nav.*.label' => ['required_with:nav', 'string', 'max:60'],
            'nav.*.href' => ['required_with:nav', 'string', 'max:500', new SiteHref],
            'footer_columns' => ['nullable', 'array', 'max:6'],
            'footer_columns.*.heading' => ['required_with:footer_columns', 'string', 'max:60'],
            'footer_columns.*.links' => ['required_with:footer_columns', 'array', 'max:12'],
            'footer_columns.*.links.*.label' => ['required', 'string', 'max:60'],
            'footer_columns.*.links.*.href' => ['required', 'string', 'max:500', new SiteHref],
            'social' => ['nullable', 'array', 'max:12'],
            'social.*.network' => ['required_with:social', 'string', 'max:40'],
            'social.*.url' => ['required_with:social', 'url', 'max:500'],
            'ga_measurement_id' => ['nullable', 'string', 'max:32', 'regex:/^G-[A-Z0-9]+$/'],
            'gsc_verification' => ['nullable', 'string', 'max:128'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'sales_email' => ['nullable', 'email', 'max:255'],
            'announcement' => ['nullable', 'string', 'max:200'],
            'announcement_href' => ['nullable', 'string', 'max:500', new SiteHref],
            'custom_head' => ['nullable', 'string', 'max:5000'],
            'custom_body' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
