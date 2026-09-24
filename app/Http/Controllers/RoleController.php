<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'roles' => Role::with('permissions:id,slug,name')
                ->withCount('users')
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::orderBy('name')->get(['id', 'name', 'slug', 'description']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('roles', 'slug')],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['integer'],
        ]);

        $permissionIds = $this->resolvePermissionIds($data['permissions']);

        $role = Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);
        $role->permissions()->sync($permissionIds);

        return response()->json([
            'message' => 'Role created.',
            'role' => $role->load('permissions:id,slug,name'),
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['integer'],
        ]);

        $role->update(['name' => $data['name']]);
        $role->permissions()->sync($this->resolvePermissionIds($data['permissions']));

        return response()->json([
            'message' => 'Role updated.',
            'role' => $role->load('permissions:id,slug,name'),
        ]);
    }

    private function resolvePermissionIds(array $ids): array
    {
        return Permission::whereIn('id', $ids)->pluck('id')->all();
    }
}
