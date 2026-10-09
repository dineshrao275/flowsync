<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuditsTenantAdminActions;
use App\Models\Permission;
use App\Models\Role;
use App\Support\GrantCeiling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    use AuditsTenantAdminActions;

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
        GrantCeiling::assertCanGrantPermissions($request->user(), $permissionIds);

        $role = Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);
        $role->permissions()->sync($permissionIds);

        $this->auditTenantAdmin($request, 'role.created', 'roles', $role->id, null, $this->snapshot($role), ['role' => $role->slug]);

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

        $this->refuseSystemRole($role, 'edited');
        GrantCeiling::assertCanEditRole($request->user(), $role);

        $permissionIds = $this->resolvePermissionIds($data['permissions']);
        GrantCeiling::assertCanGrantPermissions(
            $request->user(),
            $permissionIds,
            $role->permissions()->pluck('permissions.id'),
        );

        $before = $this->snapshot($role);

        $role->update(['name' => $data['name']]);
        $role->permissions()->sync($permissionIds);

        $after = $this->snapshot($role);
        if ($before !== $after) {
            $this->auditTenantAdmin($request, 'role.updated', 'roles', $role->id, $before, $after, ['role' => $role->slug]);
        }

        return response()->json([
            'message' => 'Role updated.',
            'role' => $role->load('permissions:id,slug,name'),
        ]);
    }

    /**
     * Copy a role (system or custom) into a new custom role. This is how a tenant
     * customises a built-in role now that those are read-only. The copy carries
     * the source's permissions, so the actor must hold all of them (GrantCeiling).
     */
    public function clone(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('roles', 'slug')],
        ]);

        $permissionIds = $role->permissions()->pluck('permissions.id');
        GrantCeiling::assertCanGrantPermissions($request->user(), $permissionIds);

        $copy = Role::create(['name' => $data['name'], 'slug' => $data['slug']]);
        $copy->permissions()->sync($permissionIds);

        $this->auditTenantAdmin($request, 'role.created', 'roles', $copy->id, null, $this->snapshot($copy), [
            'role' => $copy->slug,
            'cloned_from' => $role->slug,
        ]);

        return response()->json([
            'message' => 'Role duplicated.',
            'role' => $copy->load('permissions:id,slug,name')->loadCount('users'),
        ], 201);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->refuseSystemRole($role, 'deleted');

        $members = $role->users()->count();
        if ($members > 0) {
            throw ValidationException::withMessages([
                'form' => "{$role->name} is assigned to {$members} ".($members === 1 ? 'user' : 'users').'. Move them to another role first.',
            ]);
        }

        $before = $this->snapshot($role);
        $slug = $role->slug;
        $id = $role->id;

        $role->permissions()->detach();
        $role->delete();

        $this->auditTenantAdmin($request, 'role.deleted', 'roles', $id, $before, null, ['role' => $slug]);

        return response()->json(['message' => 'Role deleted.']);
    }

    private function refuseSystemRole(Role $role, string $verb): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'form' => "{$role->name} is a built-in role and cannot be {$verb}. Duplicate it to make a customised copy.",
            ]);
        }
    }

    /** @return array{name: string, permissions: list<string>} */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('slug')->sort()->values()->all(),
        ];
    }

    private function resolvePermissionIds(array $ids): array
    {
        return Permission::whereIn('id', $ids)->pluck('id')->all();
    }
}
