<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api_markdown` — a task description is stored as HTML (the SPA's rich-text editor writes HTML and renders it
 * through DOMPurify), but an agent writes Markdown. On the token write routes the `description` is converted
 * to HTML before the controller sees it, so an agent-written task looks like a human-written one instead of
 * showing literal `**` and collapsed line breaks. Raw HTML in the input is stripped, not passed through.
 */
class ConvertApiMarkdown
{
    public function handle(Request $request, Closure $next): Response
    {
        $description = $request->input('description');

        if (is_string($description) && trim($description) !== '') {
            $request->merge([
                'description' => rtrim(Str::markdown($description, ['html_input' => 'strip', 'allow_unsafe_links' => false])),
            ]);
        }

        return $next($request);
    }
}
