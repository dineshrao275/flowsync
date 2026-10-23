<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Support\AppName;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public blog (no auth): paginated index, category archives, post pages,
 * RSS feed, and signed draft previews. Only published, non-noindexed rows
 * surface; everything else 404s.
 */
class BlogController extends Controller
{
    private const PER_PAGE = 9;

    public function index(Request $request): View
    {
        $posts = $this->visible()->paginate(self::PER_PAGE)->withQueryString();

        return $this->render('blog.index', [
            'posts' => $posts,
            'title' => 'Blog',
            'seoTitle' => 'Blog — '.AppName::get(),
            'metaDescription' => 'Guides, changelogs, and notes from the '.AppName::get().' team.',
            'crumbs' => [['name' => 'Home', 'url' => url('/')], ['name' => 'Blog']],
        ]);
    }

    public function category(string $slug): View|Response
    {
        $category = BlogCategory::where('slug', $slug)->first();

        if (! $category) {
            abort(404);
        }

        $posts = $this->visible()->where('category_id', $category->id)->paginate(self::PER_PAGE)->withQueryString();

        return $this->render('blog.index', [
            'posts' => $posts,
            'category' => $category,
            'title' => $category->name,
            'seoTitle' => $category->name.' — Blog',
            'metaDescription' => $category->description ?: "Posts in {$category->name}.",
            'crumbs' => [
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Blog', 'url' => url('/blog')],
                ['name' => $category->name],
            ],
        ]);
    }

    public function show(string $slug): View|Response
    {
        $post = $this->visible()->where('slug', $slug)->first();

        if (! $post) {
            abort(404);
        }

        $related = $this->visible()
            ->where('id', '!=', $post->id)
            ->when($post->category_id, fn ($query) => $query->where('category_id', $post->category_id))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return $this->render('blog.show', [
            'post' => $post,
            'related' => $related,
            'title' => $post->title,
            'seoTitle' => $post->seo_title ?: $post->title,
            'metaDescription' => $post->excerpt ?: $post->meta_description,
            'canonicalOverride' => $post->canonical_url,
            'forceRobots' => $post->noindex ? 'noindex, follow' : null,
            'ogImage' => $post->cover?->url(),
            'crumbs' => [
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Blog', 'url' => url('/blog')],
                ['name' => $post->title],
            ],
            'article' => [
                'headline' => $post->title,
                'description' => $post->excerpt,
                'image' => $post->cover?->url(),
                'datePublished' => $post->published_at?->toIso8601String(),
                'dateModified' => $post->updated_at?->toIso8601String(),
                'author' => $post->author_name,
            ],
        ]);
    }

    public function feed(): Response
    {
        $posts = $this->visible()->orderByDesc('published_at')->limit(20)->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0"><channel>'."\n"
            .'<title>'.e(AppName::get().' Blog').'</title>'."\n"
            .'<link>'.e(url('/blog')).'</link>'."\n"
            .'<description>'.e('Guides, changelogs, and notes from the '.AppName::get().' team.').'</description>'."\n";

        foreach ($posts as $post) {
            $xml .= '<item>'
                .'<title>'.e($post->title).'</title>'
                .'<link>'.e(url('/blog/'.$post->slug)).'</link>'
                .'<guid>'.e(url('/blog/'.$post->slug)).'</guid>'
                .($post->published_at ? '<pubDate>'.$post->published_at->toRfc2822String().'</pubDate>' : '')
                .($post->excerpt ? '<description>'.e($post->excerpt).'</description>' : '')
                .'</item>'."\n";
        }

        return response($xml.'</channel></rss>', 200)->header('Content-Type', 'application/rss+xml');
    }

    public function preview(Request $request, BlogPost $blogPost): View|Response
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $blogPost->load(['category', 'cover']);

        return response()
            ->view('blog.show', $this->pageData([
                'post' => $blogPost,
                'related' => collect(),
                'title' => $blogPost->title,
                'seoTitle' => $blogPost->seo_title ?: $blogPost->title,
                'metaDescription' => $blogPost->excerpt ?: $blogPost->meta_description,
                'forceRobots' => 'noindex, nofollow',
                'ogImage' => $blogPost->cover?->url(),
                'crumbs' => [],
            ]))
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function sitemapUrls(): array
    {
        $urls = [];

        foreach (BlogCategory::has('posts')->orderBy('sort_order')->get() as $category) {
            $urls[] = ['loc' => url('/blog/category/'.$category->slug), 'lastmod' => $category->updated_at->toDateString()];
        }

        foreach ($this->visible()->where('noindex', false)->orderByDesc('published_at')->get(['slug', 'updated_at']) as $post) {
            $urls[] = ['loc' => url('/blog/'.$post->slug), 'lastmod' => $post->updated_at->toDateString()];
        }

        return $urls;
    }

    private function visible()
    {
        return BlogPost::with(['category', 'cover'])
            ->where('status', BlogPost::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at');
    }

    private function render(string $view, array $data): View
    {
        return view($view, $this->pageData($data));
    }

    private function pageData(array $data): array
    {
        return $data + [
            'siteName' => AppName::get(),
            'crumbs' => [],
            'faqSchema' => [],
        ];
    }
}
