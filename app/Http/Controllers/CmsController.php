<?php

namespace App\Http\Controllers;

use App\Http\Requests\CmsPageRequest;
use App\Models\WebsitePage;
use App\Services\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CMS admin (super admin). CRUD + publish lifecycle for the public site's
 * database-backed pages. Content is an ordered array of typed blocks rendered
 * server-side by PublicSiteController.
 */
class CmsController extends Controller
{
    /**
     * The fields the audit trail records — snapshots are reduced to these, so
     * a page edit logs the before/after of what changed, never a row dump.
     */
    private const AUDIT_FIELDS = [
        'slug', 'title', 'content', 'status', 'seo_title', 'meta_description',
        'og_image', 'sitemap_include', 'sort_order', 'published_at',
    ];

    public function index(): JsonResponse
    {
        $pages = WebsitePage::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (WebsitePage $page) => $page->only(
                ['id', 'slug', 'title', 'status', 'seo_title', 'meta_description', 'og_image', 'sitemap_include', 'sort_order', 'published_at']
            ));

        return response()->json(['pages' => $pages]);
    }

    public function show(WebsitePage $websitePage): JsonResponse
    {
        return response()->json(['page' => $websitePage]);
    }

    public function store(CmsPageRequest $request): JsonResponse
    {
        $data = $request->validated();

        $page = WebsitePage::create($data + ['published_at' => $data['status'] === WebsitePage::STATUS_PUBLISHED ? now() : null]);

        $this->audit($request, 'cms.page_created', $page, null, $this->snapshot($page));

        return response()->json(['page' => $page], 201);
    }

    public function update(CmsPageRequest $request, WebsitePage $websitePage): JsonResponse
    {
        $data = $request->validated();

        $before = $this->snapshot($websitePage);

        if ($data['status'] === WebsitePage::STATUS_PUBLISHED && ! $websitePage->isPublished()) {
            $data['published_at'] = now();
        }

        $websitePage->update($data);

        $this->audit($request, 'cms.page_updated', $websitePage, $before, $this->snapshot($websitePage));

        return response()->json(['page' => $websitePage->fresh()]);
    }

    public function destroy(Request $request, WebsitePage $websitePage): JsonResponse
    {
        if ($websitePage->slug === 'home') {
            abort(422, 'Cannot delete the home page.');
        }

        $this->audit($request, 'cms.page_deleted', $websitePage, $this->snapshot($websitePage), null);

        $websitePage->delete();

        return response()->json(['message' => 'Page deleted.']);
    }

    public function publish(Request $request, WebsitePage $websitePage): JsonResponse
    {
        $before = $this->snapshot($websitePage);

        $websitePage->publish();

        $this->audit($request, 'cms.page_published', $websitePage, $before, $this->snapshot($websitePage));

        return response()->json(['page' => $websitePage->fresh(), 'message' => 'Page published.']);
    }

    public function unpublish(Request $request, WebsitePage $websitePage): JsonResponse
    {
        $before = $this->snapshot($websitePage);

        $websitePage->unpublish();

        $this->audit($request, 'cms.page_unpublished', $websitePage, $before, $this->snapshot($websitePage));

        return response()->json(['page' => $websitePage->fresh(), 'message' => 'Page taken down.']);
    }

    private function audit(Request $request, string $action, WebsitePage $page, ?array $before, ?array $after): void
    {
        app(PlatformAudit::class)->diff(
            $request,
            $action,
            'website_pages',
            $page->id,
            $before,
            $after,
            ['slug' => $page->slug],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(WebsitePage $page): array
    {
        $row = $page->only(self::AUDIT_FIELDS);

        // Normalise the timestamp so an unchanged `published_at` compares by
        // value — two Carbon instances are never identity-equal, and the diff
        // would otherwise flag every publish no-op as a change.
        $row['published_at'] = $page->published_at !== null ? (string) $page->published_at : null;

        return $row;
    }
}
