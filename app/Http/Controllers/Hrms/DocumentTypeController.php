<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\DocumentTypeRequest;
use App\Models\Hrms\Document\DocumentType;
use App\Services\Hrms\DocumentService;
use Illuminate\Http\JsonResponse;

/**
 * Document/HRMS — the upload picker’s catalogue.
 *
 * Read-only by design: types are seeded system rows a tenant may rename, and
 * P13.3 ships no admin mutation for them. Active rows only, so a retired type
 * cannot be resurrected through a picker that still lists it.
 */
class DocumentTypeController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(DocumentTypeRequest $request): JsonResponse
    {
        $types = $this->documents->types($request->filters());

        return response()->json([
            'document_types' => $types->map(fn (DocumentType $type): array => $this->present($type))->all(),
        ]);
    }

    public function show(int $type): JsonResponse
    {
        return response()->json([
            'document_type' => $this->present($this->documents->type($type)),
        ]);
    }

    private function present(DocumentType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
            'category' => $type->category->value,
            'is_mandatory' => $type->is_mandatory,
            'requires_expiry' => $type->requires_expiry,
            'is_sensitive' => $type->is_sensitive,
        ];
    }
}
