<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The rule that keeps `roles.manage` / `users.manage` from being a ladder:
 * nobody can hand out access they do not hold themselves.
 *
 *  - a role may only GAIN permissions the actor holds (permissions it already
 *    has are left alone, so re-saving a form that echoes the full set is fine);
 *  - a user may only be GIVEN roles whose permissions the actor holds;
 *  - the `admin` role can be assigned or edited only by an admin.
 *
 * An `admin` is treated as holding every permission (the role is `*` in
 * config; its stored snapshot can lag a catalog addition until the next
 * `tenants:provision`, and that lag must not stop an admin granting).
 */
final class GrantCeiling
{
    public const ADMIN_ROLE = 'admin';

    /**
     * @param  iterable<int>  $newPermissionIds  the full set being saved
     * @param  iterable<int>  $currentPermissionIds  what the role holds now ([] on create)
     */
    public static function assertCanGrantPermissions(User $actor, iterable $newPermissionIds, iterable $currentPermissionIds = [], string $field = 'permissions'): void
    {
        if (self::isAdmin($actor)) {
            return;
        }

        $added = collect($newPermissionIds)->map(fn ($id) => (int) $id)
            ->diff(collect($currentPermissionIds)->map(fn ($id) => (int) $id));

        if ($added->isEmpty()) {
            return;
        }

        $missing = Permission::whereIn('id', $added)->pluck('slug')
            ->diff($actor->permissionSlugs());

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                $field => 'You cannot grant permissions you do not hold: '.$missing->sort()->implode(', ').'.',
            ]);
        }
    }

    /**
     * @param  Collection<int, int>  $newRoleIds  role ids the user will end up with
     * @param  Collection<int, int>  $currentRoleIds  role ids the user holds now ([] on create)
     */
    public static function assertCanAssignRoles(User $actor, Collection $newRoleIds, Collection $currentRoleIds, string $field = 'roles'): void
    {
        if (self::isAdmin($actor)) {
            return;
        }

        $added = $newRoleIds->map(fn ($id) => (int) $id)->diff($currentRoleIds->map(fn ($id) => (int) $id));

        if ($added->isEmpty()) {
            return;
        }

        $roles = Role::with('permissions:id,slug')->whereIn('id', $added)->get();

        if ($roles->contains('slug', self::ADMIN_ROLE)) {
            throw ValidationException::withMessages([
                $field => 'Only an administrator can assign the '.self::ADMIN_ROLE.' role.',
            ]);
        }

        $held = $actor->permissionSlugs();

        foreach ($roles as $role) {
            $beyond = $role->permissions->pluck('slug')->diff($held);

            if ($beyond->isNotEmpty()) {
                throw ValidationException::withMessages([
                    $field => "You cannot assign the {$role->name} role: it carries permissions you do not hold.",
                ]);
            }
        }
    }

    public static function assertCanEditRole(User $actor, Role $role): void
    {
        if ($role->slug === self::ADMIN_ROLE && ! self::isAdmin($actor)) {
            throw ValidationException::withMessages([
                'form' => 'Only an administrator can edit the '.self::ADMIN_ROLE.' role.',
            ]);
        }
    }

    private static function isAdmin(User $actor): bool
    {
        return $actor->hasRole(self::ADMIN_ROLE);
    }
}
