<?php

namespace App\Http\Controllers\Hrms\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\ExpenseCategoryRequest;
use App\Models\Hrms\Expense\ExpenseCategory;
use App\Services\Hrms\Expense\ExpenseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Expense/HRMS — the claim catalogue surface.
 *
 * Thin: it authorizes against the category policy, hands the payload to
 * the catalog service, and shapes the response. Starter and in-use rows
 * refuse inside the service, not here.
 */
class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly ExpenseCategoryService $catalog) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        return response()->json([
            'categories' => ExpenseCategory::query()->orderBy('position')->orderBy('name')
                ->get()->map(fn (ExpenseCategory $category): array => $this->present($category))->all(),
        ]);
    }

    public function show(ExpenseCategory $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json(['category' => $this->present($category)]);
    }

    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', ExpenseCategory::class);

        $category = $this->catalog->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'Category created.',
            'category' => $this->present($category),
        ], Response::HTTP_CREATED);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('update', $category);

        $updated = $this->catalog->update($category, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Category updated.',
            'category' => $this->present($updated),
        ]);
    }

    public function destroy(Request $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $this->catalog->delete($category, $request->user());

        return response()->json(['message' => 'Category deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ExpenseCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'requires_receipt_above' => $category->requires_receipt_above === null ? null : (string) $category->requires_receipt_above,
            'is_reimbursable' => $category->is_reimbursable,
            'payroll_component_id' => $category->payroll_component_id,
            'is_active' => $category->is_active,
            'position' => $category->position,
            'is_system' => $category->is_system,
        ];
    }
}
