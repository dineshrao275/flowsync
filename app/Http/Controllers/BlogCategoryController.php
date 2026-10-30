<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BlogCategoryRequest;
use App\Models\BlogCategory;
use App\Services\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CMS admin (super admin). Blog categories. A category holding posts cannot
 * be deleted (422) — recategorize first.
 */
class BlogCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = BlogCategory::withCount('posts')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['categories' => $categories]);
    }

    public function store(BlogCategoryRequest $request): JsonResponse
    {
        $category = BlogCategory::create($request->validated());

        app(PlatformAudit::class)->diff(
            $request, 'cms.category_created', 'blog_categories', $category->id,
            null, $category->only(['slug', 'name']), ['slug' => $category->slug],
        );

        return response()->json(['category' => $category->fresh()], 201);
    }

    public function update(BlogCategoryRequest $request, BlogCategory $blogCategory): JsonResponse
    {
        $before = $blogCategory->only(['slug', 'name', 'description', 'sort_order']);

        $blogCategory->update($request->validated());

        app(PlatformAudit::class)->diff(
            $request, 'cms.category_updated', 'blog_categories', $blogCategory->id,
            $before, $blogCategory->fresh()->only(['slug', 'name', 'description', 'sort_order']), ['slug' => $blogCategory->slug],
        );

        return response()->json(['category' => $blogCategory->fresh()]);
    }

    public function destroy(Request $request, BlogCategory $blogCategory): JsonResponse
    {
        if ($blogCategory->posts()->exists()) {
            abort(422, __('Move or delete the category posts first.'));
        }

        app(PlatformAudit::class)->diff(
            $request, 'cms.category_deleted', 'blog_categories', $blogCategory->id,
            $blogCategory->only(['slug', 'name']), null, ['slug' => $blogCategory->slug],
        );

        $blogCategory->delete();

        return response()->json(['message' => __('Category deleted.')]);
    }
}
