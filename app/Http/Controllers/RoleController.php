<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuditsTenantAdminActions;
use App\Http\Requests\RoleCloneRequest;
use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\TenantLimits;
use App\Support\GrantCeiling;
use App\Support\ProductCatalog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    use AuditsTenantAdminActions;

    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::orderBy('name')->get(['id', 'name', 'slug', 'description']);

        // A tenant only sees the permissions of its subscribed products (plus
        // the shared identity/administration prefixes): an HRMS-only tenant
        // must not view TMS permissions, and vice versa. A platform super
        // admin without a tenant context manages the whole catalog.
        if (($allowed = $this->allowedPermissionSlugs()) !== null) {
            $set = array_flip($allowed);
            $permissions = $permissions->filter(fn (Permission $p) => isset($set[$p->slug]))->values();
        }

        return response()->json([
            'roles' => Role::with('permissions:id,slug,name')
                ->withCount('users')
                ->orderBy('name')
                ->get(),
            // Each catalog row names its product ('tms', 'hrms', 'shared') so
            // the Roles page can badge it; the list itself is already scoped
            // to the tenant's subscribed products above.
            'permissions' => $permissions->map(fn (Permission $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'product' => ProductCatalog::ofPermission($p->slug) ?? 'shared',
            ])->values(),
        ]);
    }

    public function store(RoleStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $permissionIds = $this->resolvePermissionIds($data['permissions']);
        $this->assertPermissionsInProducts($permissionIds);
        GrantCeiling::assertCanGrantPermissions($request->user(), $permissionIds);

        $role = Role::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);
        $role->permissions()->sync($permissionIds);

        $this->auditTenantAdmin($request, 'role.created', 'roles', $role->id, null, $this->snapshot($role), ['role' => $role->slug]);

        return response()->json([
            'message' => __('Role created.'),
            'role' => $role->load('permissions:id,slug,name'),
        ], 201);
    }

    public function update(RoleUpdateRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();

        $this->refuseSystemRole($role, __('edited'));
        GrantCeiling::assertCanEditRole($request->user(), $role);

        $permissionIds = $this->resolvePermissionIds($data['permissions']);
        $this->assertPermissionsInProducts($permissionIds);
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
            'message' => __('Role updated.'),
            'role' => $role->load('permissions:id,slug,name'),
        ]);
    }

    /**
     * Copy a role (system or custom) into a new custom role. This is how a tenant
     * customises a built-in role now that those are read-only. The copy carries
     * the source's permissions, so the actor must hold all of them (GrantCeiling).
     */
    public function clone(RoleCloneRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();

        $permissionIds = $role->permissions()->pluck('permissions.id');
        $this->assertPermissionsInProducts($permissionIds->all());
        GrantCeiling::assertCanGrantPermissions($request->user(), $permissionIds);

        $copy = Role::create(['name' => $data['name'], 'slug' => $data['slug']]);
        $copy->permissions()->sync($permissionIds);

        $this->auditTenantAdmin($request, 'role.created', 'roles', $copy->id, null, $this->snapshot($copy), [
            'role' => $copy->slug,
            'cloned_from' => $role->slug,
        ]);

        return response()->json([
            'message' => __('Role duplicated.'),
            'role' => $copy->load('permissions:id,slug,name')->loadCount('users'),
        ], 201);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->refuseSystemRole($role, __('deleted'));

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

        return response()->json(['message' => __('Role deleted.')]);
    }

    private function refuseSystemRole(Role $role, string $verb): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'form' => __(':role is a built-in role and cannot be :verb. Duplicate it to make a customised copy.', ['role' => $role->name, 'verb' => $verb]),
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

    /**
     * Permission slugs this caller may see/grant: the shared prefixes plus the
     * tenant's subscribed products. Null = unscoped (a platform super admin
     * with no tenant context manages the whole catalog).
     *
     * @return list<string>|null
     */
    private function allowedPermissionSlugs(): ?array
    {
        $tenantId = app(TenantContext::class)->currentId();

        if ($tenantId === null) {
            return null;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return null;
        }

        $enabled = app(TenantLimits::class)->coveredProducts($tenant);

        return collect(config('permissions.permissions', []))
            ->pluck('slug')
            ->filter(fn (string $slug) => ProductCatalog::isAllowedFor($slug, $enabled))
            ->values()
            ->all();
    }

    /** Refuse permission ids outside the tenant's subscribed products (422 `permissions`). */
    private function assertPermissionsInProducts(array $permissionIds): void
    {
        $allowed = $this->allowedPermissionSlugs();

        if ($allowed === null) {
            return;
        }

        $set = array_flip($allowed);
        $offending = Permission::whereIn('id', $permissionIds)->pluck('slug')
            ->reject(fn (string $slug) => isset($set[$slug]))
            ->values()
            ->all();

        if ($offending !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'These permissions are not part of your subscription: '.implode(', ', $offending).'.',
            ]);
        }
    }
}
