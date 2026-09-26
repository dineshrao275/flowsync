<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The tenant's "default" user: undeletable, and shiftable by a tenant admin or
 * a super admin onto another admin.
 */
class DefaultUserTest extends TestCase
{
    use IsolatesDatabase;

    private function loginAs(string $email): User
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');

        return User::where('email', $email)->firstOrFail();
    }

    private function impersonateAcmeAdmin(): void
    {
        // The target's id is tenant-LOCAL, so read it off the tenant database
        // (the super-admin login left the default connection on the system DB).
        $this->connectTenant('acme');
        $targetId = User::where('email', 'admin@flowsync.test')->value('id');

        $this->postJson('/api/impersonate', [
            'tenant_id' => $this->acme()->id,
            'user_id' => $targetId,
        ])->assertOk();

        $this->assertTrue((bool) session('impersonate'));

        // The impersonated user is the acting tenant user from here on.
        $this->connectTenant('acme');
    }

    public function test_exactly_one_default_user_exists_per_tenant(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->assertSame(1, User::query()->default()->count());

        $default = User::defaultUser();
        $this->assertNotNull($default);
        $this->assertTrue($default->hasRole('admin'));
    }

    public function test_the_default_user_is_exposed_in_the_user_list(): void
    {
        $this->loginAs('admin@flowsync.test');

        $response = $this->getJson('/api/users')->assertOk();
        $users = collect($response->json('users'));

        $this->assertSame(1, $users->where('is_default', true)->count());
        $this->assertTrue($users->firstWhere('is_default', true)['id'] === User::defaultUser()->id);
    }

    public function test_the_default_user_cannot_be_deleted(): void
    {
        $this->loginAs('admin@flowsync.test');

        $default = User::defaultUser();

        $this->deleteJson("/api/users/{$default->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');

        $this->assertDatabaseHas('users', ['id' => $default->id, 'is_default' => true]);
    }

    public function test_the_model_refuses_to_delete_the_default_user(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->expectException(ValidationException::class);

        User::defaultUser()->delete();
    }

    public function test_a_non_default_user_can_be_deleted(): void
    {
        $this->loginAs('admin@flowsync.test');

        $viewer = User::where('email', 'viewer@flowsync.test')->firstOrFail();

        $this->deleteJson("/api/users/{$viewer->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $viewer->id]);
    }

    public function test_deleting_a_user_also_drops_the_login_routing_row(): void
    {
        $this->loginAs('admin@flowsync.test');

        $target = User::where('email', 'viewer@flowsync.test')->firstOrFail();
        $tenantId = $this->acme()->id;

        $this->assertTrue(
            TenantUserRouting::where('tenant_id', $tenantId)->where('email', $target->email)->exists(),
            'the seeded user should be present in the central routing index',
        );

        $this->deleteJson("/api/users/{$target->id}")->assertOk();

        $this->assertFalse(
            TenantUserRouting::where('tenant_id', $tenantId)->where('email', $target->email)->exists(),
        );
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $admin = $this->loginAs('admin@flowsync.test');

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_a_tenant_admin_can_shift_the_default_to_another_admin(): void
    {
        $this->loginAs('admin@flowsync.test');

        $previous = User::defaultUser();
        $next = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->putJson("/api/users/{$next->id}/roles", ['roles' => ['admin']])->assertOk();
        $this->assertTrue($next->fresh()->hasRole('admin'));
        $this->assertNotSame($previous->id, $next->id);

        $this->putJson("/api/users/{$next->id}/default")
            ->assertOk()
            ->assertJsonPath('user.is_default', true)
            ->assertJsonPath('user.id', $next->id);

        // Exactly one default, and the previous one lost the flag.
        $this->assertSame(1, User::query()->default()->count());
        $this->assertFalse($previous->fresh()->is_default);
        $this->assertTrue($next->fresh()->is_default);

        // The old default is now deletable.
        $this->deleteJson("/api/users/{$previous->id}")->assertOk();
    }

    public function test_shifting_the_default_to_the_current_default_keeps_exactly_one(): void
    {
        $this->loginAs('admin@flowsync.test');

        $current = User::defaultUser();
        $this->assertNotNull($current);

        $this->putJson("/api/users/{$current->id}/default")
            ->assertOk()
            ->assertJsonPath('user.is_default', true);

        $this->assertSame(1, User::query()->default()->count());
        $this->assertTrue($current->fresh()->is_default);
    }

    public function test_the_default_cannot_be_shifted_to_a_non_admin(): void
    {
        $this->loginAs('admin@flowsync.test');

        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $previous = User::defaultUser();

        $this->putJson("/api/users/{$editor->id}/default")
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');

        $this->assertTrue($previous->fresh()->is_default);
        $this->assertFalse($editor->fresh()->is_default);
    }

    public function test_promoting_a_non_admin_then_shifting_the_default_to_them_works(): void
    {
        $this->loginAs('admin@flowsync.test');

        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();

        $this->putJson("/api/users/{$editor->id}/roles", ['roles' => ['admin']])->assertOk();
        $this->putJson("/api/users/{$editor->id}/default")->assertOk();

        $this->assertTrue($editor->fresh()->is_default);
    }

    public function test_the_default_user_must_keep_the_admin_role(): void
    {
        $this->loginAs('admin@flowsync.test');

        $default = User::defaultUser();

        $this->putJson("/api/users/{$default->id}/roles", ['roles' => ['viewer']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');

        $this->assertTrue($default->fresh()->hasRole('admin'));
    }

    public function test_a_tenant_admin_can_shift_the_default_to_themselves(): void
    {
        $admin = $this->loginAs('admin@flowsync.test');

        $this->putJson("/api/users/{$admin->id}/default")->assertOk();

        $this->assertTrue($admin->fresh()->is_default);
    }

    public function test_users_without_manage_permission_cannot_shift_or_delete(): void
    {
        $viewer = $this->loginAs('viewer@flowsync.test');
        $other = User::where('email', 'editor@flowsync.test')->firstOrFail();

        $this->putJson("/api/users/{$viewer->id}/default")->assertForbidden();
        $this->putJson("/api/users/{$other->id}/default")->assertForbidden();
        $this->deleteJson("/api/users/{$other->id}")->assertForbidden();

        $this->assertFalse($viewer->fresh()->is_default);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_a_super_admin_can_shift_the_default_while_impersonating(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->impersonateAcmeAdmin();

        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->putJson("/api/users/{$editor->id}/roles", ['roles' => ['admin']])->assertOk();

        $previous = User::defaultUser();
        $this->putJson("/api/users/{$editor->id}/default")->assertOk();

        $this->assertTrue($editor->fresh()->is_default);
        $this->assertFalse($previous->fresh()->is_default);

        // And the previously default user is deletable again.
        $this->deleteJson("/api/users/{$previous->id}")->assertOk();
    }

    public function test_a_non_impersonating_super_admin_cannot_touch_tenant_users(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // The user routes live in the tenant_context group: there is no tenant
        // database to address, so every request is rejected before binding.
        $this->getJson('/api/users')->assertForbidden();
        $this->putJson('/api/users/1/default')->assertForbidden();
        $this->deleteJson('/api/users/1')->assertForbidden();
    }

    public function test_provisioning_creates_the_owner_as_the_default_user(): void
    {
        $tenant = Tenant::create([
            'name' => 'Provisioned Default',
            'slug' => 'provisioned-default',
            'status' => Tenant::STATUS_PENDING,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
        ]);

        $dbm = app(TenantDatabaseManager::class);

        app(TenantProvisioner::class)->provisionIsolated(
            $tenant,
            $dbm,
            app(TenantLifecycle::class),
        );

        $owner = $dbm->using(
            $tenant,
            fn () => User::where('email', "owner@{$tenant->slug}.test")->with('roles')->firstOrFail(),
        );

        $this->assertTrue($owner->is_default);
        $this->assertSame('admin', $owner->roles->first()->slug);

        $count = $dbm->using($tenant, fn () => User::query()->default()->count());
        $this->assertSame(1, $count);
    }

    public function test_provisioning_repairs_a_tenant_without_a_default_user(): void
    {
        $tenant = $this->acme();
        $dbm = app(TenantDatabaseManager::class);

        // Simulate a tenant created before the column existed.
        $dbm->using($tenant, function () {
            User::query()->update(['is_default' => false]);
        });

        app(TenantProvisioner::class)->provisionIsolated(
            $tenant,
            $dbm,
            app(TenantLifecycle::class),
        );

        $default = $dbm->using($tenant, fn () => User::defaultUser());
        $this->assertNotNull($default);
        $this->assertTrue($default->hasRole('admin'));
    }
}
