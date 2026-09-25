<?php

namespace App\Http\Controllers;

use App\Http\Requests\CmsPageRequest;
use App\Models\AuditLog;
use App\Models\WebsitePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CMS admin (super admin). CRUD + publish lifecycle for the public site's
 * database-backed pages. Content is an ordered array of typed blocks rendered
 * server-side by PublicSiteController.
 */
class CmsController extends Controller
{
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

        $this->audit($request, 'cms.page_created', $page);

        return response()->json(['page' => $page], 201);
    }

    public function update(CmsPageRequest $request, WebsitePage $websitePage): JsonResponse
    {
        $data = $request->validated();

        if ($data['status'] === WebsitePage::STATUS_PUBLISHED && ! $websitePage->isPublished()) {
            $data['published_at'] = now();
        }

        $websitePage->update($data);

        $this->audit($request, 'cms.page_updated', $websitePage);

        return response()->json(['page' => $websitePage->fresh()]);
    }

    public function destroy(Request $request, WebsitePage $websitePage): JsonResponse
    {
        if ($websitePage->slug === 'home') {
            abort(422, 'Cannot delete the home page.');
        }

        $this->audit($request, 'cms.page_deleted', $websitePage);

        $websitePage->delete();

        return response()->json(['message' => 'Page deleted.']);
    }

    public function publish(Request $request, WebsitePage $websitePage): JsonResponse
    {
        $websitePage->publish();

        $this->audit($request, 'cms.page_published', $websitePage);

        return response()->json(['page' => $websitePage->fresh(), 'message' => 'Page published.']);
    }

    public function unpublish(Request $request, WebsitePage $websitePage): JsonResponse
    {
        $websitePage->unpublish();

        $this->audit($request, 'cms.page_unpublished', $websitePage);

        return response()->json(['page' => $websitePage->fresh(), 'message' => 'Page taken down.']);
    }

    private function audit(Request $request, string $action, WebsitePage $page): void
    {
        AuditLog::create([
            'subject_type' => 'website_pages',
            'subject_id' => $page->id,
            'action' => $action,
            'data' => ['slug' => $page->slug, 'title' => $page->title, 'status' => $page->status],
            'actor_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
        ]);
    }
}
