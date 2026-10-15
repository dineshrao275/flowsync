<?php

namespace App\Http\Controllers\Hrms\Asset;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\AssetCategoryRequest;
use App\Models\Hrms\Asset\AssetCategory;
use App\Services\Hrms\Asset\AssetCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Asset/HRMS — the register catalogue surface.
 *
 * Thin: it authorizes against the category policy, hands the payload to
 * the catalog service, and shapes the response. Rows with assets behind
 * them refuse inside the service, not here.
 */
class AssetCategoryController extends Controller
{
    public function __construct(private readonly AssetCategoryService $catalog) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AssetCategory::class);

        return response()->json([
            'categories' => AssetCategory::query()->orderBy('position')->orderBy('name')
                ->get()->map(fn (AssetCategory $category): array => $this->present($category))->all(),
        ]);
    }

    public function show(AssetCategory $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json(['category' => $this->present($category)]);
    }

    public function store(AssetCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', AssetCategory::class);

        $category = $this->catalog->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'Category created.',
            'category' => $this->present($category),
        ], Response::HTTP_CREATED);
    }

    public function update(AssetCategoryRequest $request, AssetCategory $category): JsonResponse
    {
        $this->authorize('update', $category);

        $updated = $this->catalog->update($category, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Category updated.',
            'category' => $this->present($updated),
        ]);
    }

    public function destroy(Request $request, AssetCategory $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $this->catalog->delete($category, $request->user());

        return response()->json(['message' => 'Category deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AssetCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'default_condition' => $category->default_condition,
            'is_active' => $category->is_active,
            'position' => $category->position,
            'is_system' => $category->is_system,
        ];
    }
}
