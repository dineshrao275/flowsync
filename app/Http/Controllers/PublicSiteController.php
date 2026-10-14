<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\WebsitePage;
use App\Models\WebsiteRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Server-rendered public marketing site served from the DB-backed CMS pages
 * (editable without code changes). `/` renders the published `home` page;
 * top-level `/{slug}` renders any other published page (registered after
 * every other top-level route, with reserved slugs 404ing regardless).
 * The legacy `/page/{slug}` prefix 301s to the canonical top-level URL, and
 * the route fallback resolves `website_redirects` before rendering the 404.
 * Sitemap and robots are also derived from CMS data.
 */
class PublicSiteController extends Controller
{
    public function home(): View|Response
    {
        $page = WebsitePage::where('slug', 'home')->where('status', WebsitePage::STATUS_PUBLISHED)->first();

        if (! $page) {
            return response('This site has no published home page yet.', 404);
        }

        return $this->render($page);
    }

    public function topPage(string $slug): View|RedirectResponse|Response
    {
        if (in_array($slug, config('website.reserved_slugs', []), true)) {
            abort(404);
        }

        $page = $this->published($slug);

        if (! $page) {
            // `/{slug}` matches before the fallback, so single-segment
            // redirects resolve here; deeper paths resolve in fallback().
            return $this->redirectFor($slug) ?? abort(404);
        }

        return $this->render($page);
    }

    public function page(string $slug): RedirectResponse|Response
    {
        if (in_array($slug, config('website.reserved_slugs', []), true)) {
            abort(404);
        }

        $page = $this->published($slug);

        if (! $page) {
            abort(404);
        }

        // Legacy prefix: the canonical URL is top-level (or `/` for home).
        return redirect()->to($page->slug === 'home' ? '/' : '/'.$page->slug, 301);
    }

    public function fallback(Request $request): Response
    {
        $path = ltrim($request->path(), '/');

        // Framework surfaces keep their own 404 shape — never consult CMS data.
        foreach (['api/', 'storage/', 'build/', 'up'] as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                abort(404);
            }
        }

        return $this->redirectFor($path) ?? abort(404);
    }

    public function sitemap(): Response
    {
        $urls = WebsitePage::where('status', WebsitePage::STATUS_PUBLISHED)
            ->where('sitemap_include', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['slug', 'updated_at']);

        $base = url('/');
        $home = $base.'/';

        $homeUpdated = WebsitePage::where('slug', 'home')->value('updated_at');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .'  <url><loc>'.e($home).'</loc>'
            .($homeUpdated ? '<lastmod>'.$homeUpdated->toDateString().'</lastmod>' : '')
            .'</url>'."\n";

        foreach ($urls as $page) {
            if ($page->slug === 'home') {
                continue;
            }
            $xml .= '  <url><loc>'.e($base.'/'.$page->slug).'</loc>'
                .'<lastmod>'.$page->updated_at?->toDateString().'</lastmod></url>'."\n";
        }

        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $text = "User-agent: *\n"
            ."Allow: /\n"
            .'Sitemap: '.url('/sitemap.xml')."\n";

        return response($text, 200)->header('Content-Type', 'text/plain');
    }

    private function redirectFor(string $fromPath): ?RedirectResponse
    {
        $redirect = WebsiteRedirect::where('from_path', $fromPath)->first();

        if (! $redirect) {
            return null;
        }

        $redirect->recordHit();

        return redirect()->to($redirect->to_path, $redirect->status_code === 302 ? 302 : 301);
    }

    private function published(string $slug): ?WebsitePage
    {
        return WebsitePage::where('slug', $slug)
            ->where('status', WebsitePage::STATUS_PUBLISHED)
            ->first();
    }

    private function render(WebsitePage $page): View
    {
        $crumbs = $page->slug === 'home'
            ? []
            : [
                ['name' => 'Home', 'url' => url('/')],
                ['name' => $page->title],
            ];

        return view('public.page', [
            'page' => $page,
            'siteName' => (string) PlatformSetting::value('app_name', 'FlowSync'),
            'crumbs' => $crumbs,
        ]);
    }
}
