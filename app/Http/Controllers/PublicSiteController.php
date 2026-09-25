<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\WebsitePage;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Server-rendered public marketing site served from the DB-backed CMS pages
 * (editable without code changes). `/` renders the published `home` page;
 * other routes render any published page by slug. Sitemap and robots are also
 * derived from CMS data.
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

    public function page(string $slug): View|Response
    {
        $page = WebsitePage::where('slug', $slug)->where('status', WebsitePage::STATUS_PUBLISHED)->first();

        if (! $page) {
            abort(404);
        }

        return $this->render($page);
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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .'  <url><loc>'.e($home).'</loc></url>'."\n";

        foreach ($urls as $page) {
            if ($page->slug === 'home') {
                continue;
            }
            $xml .= '  <url><loc>'.e($base.'/page/'.$page->slug).'</loc>'
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

    private function render(WebsitePage $page): View
    {
        return view('public.page', [
            'page' => $page,
            'siteName' => (string) PlatformSetting::value('app_name', 'FlowSync'),
        ]);
    }
}
