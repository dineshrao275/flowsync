<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\WebsitePage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CmsPageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::notIn(config('website.reserved_slugs', [])), Rule::unique('website_pages', 'slug')->ignore($this->route('websitePage'))],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'array'],
            'status' => ['required', 'string', Rule::in([WebsitePage::STATUS_DRAFT, WebsitePage::STATUS_PUBLISHED])],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url', 'max:500'],
            'noindex' => ['nullable', 'boolean'],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:500'],
            'sitemap_include' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
