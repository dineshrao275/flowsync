<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiDocumentRequest;
use App\Services\Ai\Documents\AiDocumentService;
use Illuminate\Http\JsonResponse;

class AiDocumentController extends Controller
{
    public function __construct(private readonly AiDocumentService $documents) {}

    public function store(AiDocumentRequest $request): JsonResponse
    {
        $document = $this->documents->store($request->user(), $request->file('file'), $request->integer('project_id') ?: null);

        return response()->json(['document' => [
            'id' => $document->id,
            'name' => $document->original_name,
            'size' => $document->size,
            'pages' => $document->pages,
        ]], 201);
    }
}
