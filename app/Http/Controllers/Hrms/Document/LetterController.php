<?php

namespace App\Http\Controllers\Hrms\Document;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Document\LetterTemplate;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Document\LetterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Letter templates and generation. Everything here needs `hrms.documents.manage`. */
class LetterController extends Controller
{
    public function __construct(private readonly LetterService $letters) {}

    public function index(Request $request): JsonResponse
    {
        $this->gate($request);

        return response()->json([
            'templates' => LetterTemplate::query()->orderBy('name')->get(),
            'fields' => config('letters.fields'),
            'starters' => collect(config('letters.starters'))->map(fn ($s, $slug) => ['slug' => $slug, 'name' => $s['name']])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate($request);
        $data = $this->validated($request);

        $template = LetterTemplate::create($data + ['slug' => $this->freeSlug(Str::slug($data['name']))]);

        return response()->json(['message' => __('Template saved.'), 'template' => $template], 201);
    }

    public function update(Request $request, LetterTemplate $template): JsonResponse
    {
        $this->gate($request);
        $template->update($this->validated($request));

        return response()->json(['message' => __('Template saved.'), 'template' => $template->refresh()]);
    }

    public function destroy(Request $request, LetterTemplate $template): JsonResponse
    {
        $this->gate($request);
        $template->delete();

        return response()->json(['message' => __('Template removed.')]);
    }

    public function addStarters(Request $request): JsonResponse
    {
        $this->gate($request);

        foreach (config('letters.starters') as $slug => $starter) {
            LetterTemplate::query()->firstOrCreate(['slug' => $slug], $starter);
        }

        return response()->json(['message' => __('Starter templates added.'), 'templates' => LetterTemplate::query()->orderBy('name')->get()]);
    }

    public function generate(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request);
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:letter_templates,id'],
            'fields' => ['sometimes', 'array', 'max:20'],
            'fields.*' => ['nullable', 'string', 'max:200'],
        ]);

        $template = LetterTemplate::query()->where('is_active', true)->findOrFail($data['template_id']);
        $document = $this->letters->generate($employee, $template, $data['fields'] ?? [], $request->user());

        return response()->json(['message' => __('Letter generated and filed in the employee\'s documents.'), 'document_id' => $document->id], 201);
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->hasPermission('hrms.documents.manage'), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'document_type_slug' => ['required', 'string', 'max:64', 'exists:document_types,slug'],
            'body' => ['required', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function freeSlug(string $base): string
    {
        $slug = $base ?: 'letter';
        for ($i = 2; LetterTemplate::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }
}
