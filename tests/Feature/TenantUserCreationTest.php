<?php

namespace Tests\Feature;

use App\Models\TenantUserRouting;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Creating a user inside an already-provisioned tenant must produce an account
 * that can actually log in.
 *
 * In isolated mode (Phase 13) login resolves the target tenant database from
 * the central `tenant_users` routing index, so a tenant `users` row with no
 * matching routing row is an account nobody can ever sign into — `destroy`
 * already deleted such a row that `store` never created.
 */
class TenantUserCreationTest extends TestCase
{
    use IsolatesDatabase;

    private function createUser(array $overrides = []): TestResponse
    {
        $this->loginAs('admin@flowsync.test');

        return $this->postJson('/api/users', array_merge([
            'name' => 'New Hire',
            'email' => 'new.hire@acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'roles' => ['editor'],
        ], $overrides));
    }

    public function test_creating_a_user_writes_the_central_login_routing_row(): void
    {
        $response = $this->createUser()->assertCreated();

        $userId = $response->json('user.id');

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->acme()->id,
            'email' => 'new.hire@acme.test',
            'user_id' => $userId,
            'name' => 'New Hire',
        ], 'iso_system');
    }

    public function test_a_created_user_can_log_in(): void
    {
        $this->createUser()->assertCreated();

        // A fresh session so nothing from `loginAs` leaks into the login attempt.
        $this->flushSession();

        $this->postJson('/api/auth/login', [
            'email' => 'new.hire@acme.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.email', 'new.hire@acme.test')
            ->assertJsonPath('user.roles.0', 'editor');
    }

    public function test_a_created_user_email_is_normalized_to_lowercase(): void
    {
        $response = $this->createUser(['email' => 'New.Hire@Acme.Test'])->assertCreated();

        $this->assertSame('new.hire@acme.test', $response->json('user.email'));

        $this->connectTenant('acme');
        $this->assertSame('new.hire@acme.test', User::findOrFail($response->json('user.id'))->email);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->acme()->id,
            'email' => 'new.hire@acme.test',
        ], 'iso_system');
    }

    public function test_a_created_user_can_log_in_when_registered_with_mixed_case_email(): void
    {
        $this->createUser(['email' => 'Mixed.Case@Acme.Test'])->assertCreated();

        $this->flushSession();

        $this->postJson('/api/auth/login', [
            'email' => 'mixed.case@acme.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_duplicate_emails_are_rejected_case_insensitively(): void
    {
        $this->createUser()->assertCreated();

        $this->createUser(['email' => 'NEW.HIRE@ACME.TEST'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_re_creating_a_previously_deleted_email_works(): void
    {
        $this->createUser()->assertCreated();

        $this->connectTenant('acme');
        $target = User::where('email', 'new.hire@acme.test')->firstOrFail();
        $this->deleteJson("/api/users/{$target->id}")->assertOk();

        $this->assertDatabaseMissing('tenant_users', [
            'tenant_id' => $this->acme()->id,
            'email' => 'new.hire@acme.test',
        ], 'iso_system');

        $response = $this->createUser()->assertCreated();

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->acme()->id,
            'email' => 'new.hire@acme.test',
            'user_id' => $response->json('user.id'),
        ], 'iso_system');
    }

    public function test_the_routing_row_is_scoped_to_the_callers_tenant(): void
    {
        $this->createUser()->assertCreated();

        $this->assertSame(
            1,
            TenantUserRouting::where('email', 'new.hire@acme.test')
                ->where('tenant_id', $this->acme()->id)
                ->count(),
        );
    }

    public function test_a_user_without_manage_permission_cannot_create_users(): void
    {
        $this->loginAs('viewer@flowsync.test');

        $this->postJson('/api/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'roles' => ['admin'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('tenant_users', ['email' => 'sneaky@acme.test'], 'iso_system');
    }
}
