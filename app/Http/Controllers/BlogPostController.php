<?php

namespace App\Http\Controllers;

use App\Http\Requests\BlogPostRequest;
use App\Models\BlogPost;
use App\Services\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * CMS admin (super admin). Blog posts: CRUD + publish lifecycle + signed
 * preview. Bodies are TipTap HTML rendered server-side by the public blog.
 */
class BlogPostController extends Controller
{
    private const AUDIT_FIELDS = [
        'slug', 'category_id', 'title', 'excerpt', 'status', 'author_name',
        'cover_media_id', 'seo_title', 'meta_description', 'canonical_url',
        'noindex', 'reading_minutes', 'published_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,published'],
            'category_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BlogPost::with('category')->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
        }

        if (! empty($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            $query->where(fn ($group) => $group->where('title', 'like', $term)->orWhere('slug', 'like', $term));
        }

        $paginator = $query->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'posts' => $paginator->getCollection()->map(fn (BlogPost $post) => $this->present($post)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(BlogPost $blogPost): JsonResponse
    {
        $blogPost->load(['category', 'cover']);

        $page = $blogPost->toArray();
        // The public preview route lands with the public blog (E3); until
        // then the key is simply absent instead of 500ing the editor.
        $page['preview_url'] = Route::has('site.blog_preview')
            ? URL::temporarySignedRoute('site.blog_preview', now()->addDay(), ['blogPost' => $blogPost->id])
            : null;

        return response()->json(['post' => $page]);
    }

    public function store(BlogPostRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['reading_minutes'] = $this->readingMinutes($data['body'] ?? null);

        $post = BlogPost::create($data + ['published_at' => $data['status'] === BlogPost::STATUS_PUBLISHED ? now() : null]);

        $this->audit($request, 'cms.post_created', $post, null, $this->snapshot($post));

        return response()->json(['post' => $this->present($post->fresh())], 201);
    }

    public function update(BlogPostRequest $request, BlogPost $blogPost): JsonResponse
    {
        $data = $request->validated();
        $data['reading_minutes'] = $this->readingMinutes($data['body'] ?? null);

        $before = $this->snapshot($blogPost);

        if ($data['status'] === BlogPost::STATUS_PUBLISHED && ! $blogPost->isPublished()) {
            $data['published_at'] = now();
        }

        $blogPost->update($data);

        $this->audit($request, 'cms.post_updated', $blogPost, $before, $this->snapshot($blogPost));

        return response()->json(['post' => $this->present($blogPost->fresh())]);
    }

    public function destroy(Request $request, BlogPost $blogPost): JsonResponse
    {
        $this->audit($request, 'cms.post_deleted', $blogPost, $this->snapshot($blogPost), null);

        $blogPost->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    public function publish(Request $request, BlogPost $blogPost): JsonResponse
    {
        $before = $this->snapshot($blogPost);

        $blogPost->publish();

        $this->audit($request, 'cms.post_published', $blogPost, $before, $this->snapshot($blogPost));

        return response()->json(['post' => $this->present($blogPost->fresh()), 'message' => 'Post published.']);
    }

    public function unpublish(Request $request, BlogPost $blogPost): JsonResponse
    {
        $before = $this->snapshot($blogPost);

        $blogPost->unpublish();

        $this->audit($request, 'cms.post_unpublished', $blogPost, $before, $this->snapshot($blogPost));

        return response()->json(['post' => $this->present($blogPost->fresh()), 'message' => 'Post taken down.']);
    }

    private function readingMinutes(?string $html): ?int
    {
        if (! $html || trim(strip_tags($html)) === '') {
            return null;
        }

        return max(1, (int) round(str_word_count(strip_tags($html)) / 200));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'status' => $post->status,
            'author_name' => $post->author_name,
            'reading_minutes' => $post->reading_minutes,
            'published_at' => $post->published_at,
            'category' => $post->category ? $post->category->only(['id', 'slug', 'name']) : null,
        ];
    }

    private function audit(Request $request, string $action, BlogPost $post, ?array $before, ?array $after): void
    {
        app(PlatformAudit::class)->diff(
            $request,
            $action,
            'blog_posts',
            $post->id,
            $before,
            $after,
            ['slug' => $post->slug],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BlogPost $post): array
    {
        $row = $post->only(self::AUDIT_FIELDS);
        $row['published_at'] = $post->published_at !== null ? (string) $post->published_at : null;

        return $row;
    }
}
