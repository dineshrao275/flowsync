<?php

namespace App\Http\Controllers;

use App\Models\ProjectRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectRoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = ProjectRole::orderBy('name')
            ->get()
            ->map(fn (ProjectRole $role) => $this->present($role));

        return response()->json(['roles' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(config('project_roles.permissions'))],
        ]);

        $slug = $this->uniqueSlug(Str::slug($data['name']));

        $role = ProjectRole::create([
            'name' => $data['name'],
            'slug' => $slug,
            'is_system' => false,
            'permissions' => array_values($data['permissions']),
        ]);

        return response()->json([
            'message' => 'Project role created.',
            'role' => $this->present($role),
        ], 201);
    }

    public function update(Request $request, ProjectRole $role): JsonResponse
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'form' => 'System project roles cannot be modified.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(config('project_roles.permissions'))],
        ]);

        $role->update([
            'name' => $data['name'],
            'permissions' => array_values($data['permissions']),
        ]);

        return response()->json([
            'message' => 'Project role updated.',
            'role' => $this->present($role),
        ]);
    }

    public function destroy(ProjectRole $role): JsonResponse
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'form' => 'System project roles cannot be deleted.',
            ]);
        }

        if ($role->members()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'This role is assigned to project members and cannot be deleted.',
            ]);
        }

        $role->delete();

        return response()->json(['message' => 'Project role deleted.']);
    }

    private function uniqueSlug(string $base): string
    {
        $candidate = $base;
        $i = 1;

        while (ProjectRole::where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.(++$i);
        }

        return $candidate;
    }

    private function present(ProjectRole $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'is_system' => $role->is_system,
            'permissions' => $role->permissions ?? [],
        ];
    }
}
