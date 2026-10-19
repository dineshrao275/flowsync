<?php

namespace App\Http\Controllers;

use App\Http\Requests\MediaUploadRequest;
use App\Models\Media;
use App\Services\PlatformAudit;
use App\Support\UploadScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CMS admin (super admin). Media library backing the public site's images:
 * upload to the public disk, searchable list, alt-text edits, delete.
 * Blocks reference rows by id and render dimensions + alt for SEO.
 */
class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Media::orderByDesc('id');

        if (! empty($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            $query->where(function ($group) use ($term): void {
                $group->where('original_name', 'like', $term)
                    ->orWhere('alt', 'like', $term);
            });
        }

        $paginator = $query->paginate((int) ($data['per_page'] ?? 24));

        return response()->json([
            'media' => $paginator->getCollection()->map(fn (Media $media) => $this->present($media)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Media $medium): JsonResponse
    {
        return response()->json(['media' => $this->present($medium)]);
    }

    public function store(MediaUploadRequest $request): JsonResponse
    {
        $file = $request->file('file');
        app(UploadScanner::class)->assertSafe($file);
        $path = $file->storeAs('media', Str::uuid().'.'.$file->getClientOriginalExtension(), 'public');

        $dimensions = @getimagesize($file->getRealPath());

        $media = Media::create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'alt' => $request->validated('alt'),
            'uploaded_by' => auth()->id(),
        ]);

        app(PlatformAudit::class)->diff(
            $request, 'cms.media_uploaded', 'media', $media->id,
            null, $media->only(['path', 'original_name', 'mime', 'size']), ['file' => $media->original_name],
        );

        return response()->json(['media' => $this->present($media)], 201);
    }

    public function update(Request $request, Media $medium): JsonResponse
    {
        $data = $request->validate(['alt' => ['nullable', 'string', 'max:255']]);

        $medium->update(['alt' => $data['alt']]);

        return response()->json(['media' => $this->present($medium->fresh())]);
    }

    public function destroy(Request $request, Media $medium): JsonResponse
    {
        app(PlatformAudit::class)->diff(
            $request, 'cms.media_deleted', 'media', $medium->id,
            $medium->only(['path', 'original_name']), null, ['file' => $medium->original_name],
        );

        Storage::disk($medium->disk)->delete($medium->path);
        $medium->delete();

        return response()->json(['message' => __('Media deleted.')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url(),
            'original_name' => $media->original_name,
            'mime' => $media->mime,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->alt,
            'created_at' => $media->created_at,
        ];
    }
}
