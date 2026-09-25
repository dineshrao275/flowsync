<?php

namespace App\Http\Requests;

use App\Models\WebsitePage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CmsPageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('website_pages', 'slug')->ignore($this->route('websitePage'))],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'array'],
            'status' => ['required', 'string', Rule::in([WebsitePage::STATUS_DRAFT, WebsitePage::STATUS_PUBLISHED])],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:500'],
            'sitemap_include' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
