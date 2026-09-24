<?php

namespace App\Http\Controllers;

use App\Models\Label;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabelController extends Controller
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $labels = $workspace->labels()
            ->orderBy('name')
            ->get()
            ->map(fn (Label $label) => $this->present($label));

        return response()->json(['labels' => $labels]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('create', new Label(['workspace_id' => $workspace->id]));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('labels', 'name')->where('workspace_id', $workspace->id)],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $label = Label::create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
        ]);

        return response()->json([
            'message' => 'Label created.',
            'label' => $this->present($label),
        ], 201);
    }

    public function update(Request $request, Label $label): JsonResponse
    {
        $this->authorize('update', $label);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('labels', 'name')
                ->where('workspace_id', $label->workspace_id)
                ->ignore($label->id)],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $label->update([
            'name' => $data['name'],
            'color' => $data['color'] ?? $label->color,
        ]);

        return response()->json([
            'message' => 'Label updated.',
            'label' => $this->present($label),
        ]);
    }

    public function destroy(Request $request, Label $label): JsonResponse
    {
        $this->authorize('delete', $label);

        $label->delete();

        return response()->json(['message' => 'Label deleted.']);
    }

    private function present(Label $label): array
    {
        return [
            'id' => $label->id,
            'workspace_id' => $label->workspace_id,
            'name' => $label->name,
            'color' => $label->color,
        ];
    }
}
