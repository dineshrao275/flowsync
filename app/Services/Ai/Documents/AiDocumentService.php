<?php

declare(strict_types=1);

namespace App\Services\Ai\Documents;

use App\Models\AiDocument;
use App\Models\User;
use App\Support\UploadScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded requirements file privately (generated name, never served back) and checks it can
 * be read before anything is spent on it. Rows are owner-only, like conversations.
 */
final class AiDocumentService
{
    private const DISK = 'local';

    public function __construct(
        private readonly AiDocumentExtractor $extractor,
        private readonly UploadScanner $scanner,
    ) {}

    public function store(User $user, UploadedFile $file, ?int $projectId): AiDocument
    {
        $this->scanner->assertSafe($file);
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $extracted = $this->extractor->extract((string) $file->getRealPath(), $extension);
        } catch (DocumentExtractionException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $path = Storage::disk(self::DISK)->putFileAs('ai/documents', $file, Str::uuid().'.'.$extension);

        return AiDocument::create([
            'user_id' => $user->id,
            'project_id' => $projectId,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'status' => 'ready',
            'pages' => $extracted->pages,
            'expires_at' => now()->addDays((int) config('ai.document_retention_days', 7)),
        ]);
    }

    /** @return array{0:ExtractedDocument,1:AiDocument} */
    public function read(User $user, int $id): array
    {
        $document = AiDocument::query()->where('user_id', $user->id)->find($id);
        if ($document === null || ! Storage::disk(self::DISK)->exists($document->path)) {
            throw ValidationException::withMessages(['document_id' => __('That document is no longer available. Upload it again.')]);
        }

        try {
            $extracted = $this->extractor->extract(Storage::disk(self::DISK)->path($document->path), pathinfo($document->path, PATHINFO_EXTENSION));
        } catch (DocumentExtractionException $e) {
            throw ValidationException::withMessages(['document_id' => $e->getMessage()]);
        }

        return [$extracted, $document];
    }

    public function fromText(string $text): ExtractedDocument
    {
        try {
            return $this->extractor->fromText($text);
        } catch (DocumentExtractionException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }
    }
}
