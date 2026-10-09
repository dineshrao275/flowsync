<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * R14 — "why can't they?": GET users/{user}/access?check=slug.
 */
class UserAccessExplainerTest extends TestCase
{
    use IsolatesDatabase;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function explain(string $email, string $check)
    {
        return $this->getJson('/api/users/'.$this->user($email)->id.'/access?check='.rawurlencode($check));
    }

    public function test_it_names_the_role_that_grants_a_permission(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->explain('editor@flowsync.test', 'workspaces.create')
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('granted_by.0.role.slug', 'editor')
            ->assertJsonPath('granted_by.0.exact', true);
    }

    public function test_it_says_when_no_role_grants_it_and_what_would(): void
    {
        $this->loginAs('admin@flowsync.test');

        $response = $this->explain('viewer@flowsync.test', 'workspaces.create')->assertOk();

        $response->assertJsonPath('allowed', false)->assertJsonPath('granted_by', []);
        $this->assertStringContainsString('None of this user', $response->json('reason'));
        $this->assertStringContainsString('workspaces.create', $response->json('reason'));
    }

    public function test_a_wider_scope_is_reported_as_satisfying_a_narrower_check(): void
    {
        $this->loginAs('admin@flowsync.test');
        $role = Role::create(['name' => 'Leave Reader', 'slug' => 'leave-reader']);
        $role->permissions()->sync(Permission::where('slug', 'hrms.leave.view_all')->pluck('id'));
        $this->user('viewer@flowsync.test')->roles()->attach($role->id);

        $response = $this->explain('viewer@flowsync.test', 'hrms.leave.view_own')->assertOk();

        $response->assertJsonPath('allowed', true);
        $granting = collect($response->json('granted_by'))->firstWhere('role.slug', 'leave-reader');
        $this->assertSame('hrms.leave.view_all', $granting['via']);
        $this->assertFalse($granting['exact']);
    }

    public function test_a_grant_is_not_enough_when_the_plan_lacks_the_module(): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => ['reports']])]);
        app(SubscriptionService::class)->assign($this->acme(), $plan);
        $this->loginAs('admin@flowsync.test');

        $response = $this->explain('admin@flowsync.test', 'hrms.leave.manage')->assertOk();

        $response->assertJsonPath('allowed', false)
            ->assertJsonPath('module.key', 'hrms.leave')
            ->assertJsonPath('module.available', false);
        $this->assertStringContainsString('plan does not include', $response->json('reason'));
    }

    public function test_an_unknown_slug_is_called_out(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->explain('admin@flowsync.test', 'nothing.like.this')
            ->assertOk()
            ->assertJsonPath('known_permission', false)
            ->assertJsonPath('allowed', false);
    }

    public function test_it_requires_roles_view_and_a_valid_check(): void
    {
        $this->loginAs('viewer@flowsync.test');
        $this->explain('editor@flowsync.test', 'workspaces.create')->assertForbidden();

        $this->loginAs('admin@flowsync.test');
        $this->explain('editor@flowsync.test', 'Bad Slug!')->assertUnprocessable();
    }
}
