<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P0.7 — `roles.manage` / `users.manage` must not be a ladder to more access
 * than the actor holds.
 */
class RoleEscalationTest extends TestCase
{
    use IsolatesDatabase;

    /** A tenant user whose only access is the given permission slugs. */
    private function operator(array $slugs): User
    {
        $this->connectTenant('acme');

        $role = Role::create(['name' => 'Operator', 'slug' => 'operator']);
        $role->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $user = User::create(['name' => 'Op', 'email' => 'op@acme.test', 'password' => 'password']);
        $user->roles()->sync([$role->id]);

        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);

        return $user;
    }

    private function permissionId(string $slug): int
    {
        return (int) Permission::where('slug', $slug)->value('id');
    }

    public function test_a_role_manager_cannot_create_a_role_with_permissions_they_lack(): void
    {
        $this->operator(['roles.view', 'roles.manage']);

        $this->postJson('/api/roles', [
            'name' => 'Backdoor',
            'slug' => 'backdoor',
            'permissions' => [$this->permissionId('users.manage'), $this->permissionId('billing.manage')],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions');

        $this->assertFalse(Role::where('slug', 'backdoor')->exists());
    }

    public function test_a_role_manager_can_create_a_role_within_their_own_permissions(): void
    {
        $this->operator(['roles.view', 'roles.manage', 'reports.view']);

        $this->postJson('/api/roles', [
            'name' => 'Reporter',
            'slug' => 'reporter',
            'permissions' => [$this->permissionId('reports.view')],
        ])->assertCreated();
    }

    public function test_a_role_manager_cannot_add_a_permission_they_lack_to_an_existing_role(): void
    {
        $this->operator(['roles.view', 'roles.manage', 'reports.view']);
        $custom = Role::create(['name' => 'Custom', 'slug' => 'custom']);
        $custom->permissions()->sync([$this->permissionId('reports.view')]);

        $this->putJson("/api/roles/{$custom->id}", [
            'name' => 'Custom',
            'permissions' => [$this->permissionId('reports.view'), $this->permissionId('users.manage')],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions');

        $this->assertSame(1, $custom->permissions()->count());
    }

    public function test_re_saving_a_role_with_permissions_the_actor_lacks_but_did_not_add_is_allowed(): void
    {
        $this->operator(['roles.view', 'roles.manage', 'reports.view']);
        $custom = Role::create(['name' => 'Custom', 'slug' => 'custom']);
        $held = [$this->permissionId('reports.view'), $this->permissionId('users.manage')];
        $custom->permissions()->sync($held);

        // A form that echoes the whole set back (rename only) is not a grant.
        $this->putJson("/api/roles/{$custom->id}", ['name' => 'Renamed', 'permissions' => $held])->assertOk();
    }

    public function test_only_an_admin_can_edit_the_admin_role(): void
    {
        $this->operator(['roles.view', 'roles.manage']);
        $admin = Role::where('slug', 'admin')->firstOrFail();

        $this->putJson("/api/roles/{$admin->id}", [
            'name' => 'Administrator',
            'permissions' => $admin->permissions()->pluck('permissions.id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('form');
    }

    public function test_a_user_manager_cannot_create_an_admin(): void
    {
        $this->operator(['users.view', 'users.manage']);

        $this->postJson('/api/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => ['admin'],
        ])->assertStatus(422)->assertJsonValidationErrors('roles');

        $this->assertFalse(User::where('email', 'sneaky@acme.test')->exists());
    }

    public function test_a_user_manager_cannot_promote_themselves_to_admin(): void
    {
        $self = $this->operator(['users.view', 'users.manage']);

        $this->putJson("/api/users/{$self->id}/roles", ['roles' => ['operator', 'admin']])
            ->assertStatus(422)->assertJsonValidationErrors('roles');

        $this->assertFalse($self->fresh()->hasRole('admin'));
    }

    public function test_a_user_manager_cannot_assign_a_role_that_carries_more_than_they_hold(): void
    {
        $this->operator(['users.view', 'users.manage']);
        $strong = Role::create(['name' => 'Strong', 'slug' => 'strong']);
        $strong->permissions()->sync([$this->permissionId('billing.manage')]);
        $target = User::create(['name' => 'T', 'email' => 't@acme.test', 'password' => 'password']);

        $this->putJson("/api/users/{$target->id}/roles", ['roles' => ['strong']])
            ->assertStatus(422)->assertJsonValidationErrors('roles');
    }

    public function test_a_user_manager_can_assign_a_role_within_their_own_permissions(): void
    {
        $this->operator(['users.view', 'users.manage', 'reports.view']);
        $lite = Role::create(['name' => 'Lite', 'slug' => 'lite']);
        $lite->permissions()->sync([$this->permissionId('reports.view')]);
        $target = User::create(['name' => 'T', 'email' => 't@acme.test', 'password' => 'password']);

        $this->putJson("/api/users/{$target->id}/roles", ['roles' => ['lite']])->assertOk();
        $this->assertTrue($target->fresh()->hasRole('lite'));
    }

    public function test_an_admin_is_unaffected(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->postJson('/api/roles', [
            'name' => 'Everything',
            'slug' => 'everything',
            'permissions' => Permission::pluck('id')->all(),
        ])->assertCreated();

        $this->postJson('/api/users', [
            'name' => 'Second Admin',
            'email' => 'second@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => ['admin'],
        ])->assertCreated();
    }
}
