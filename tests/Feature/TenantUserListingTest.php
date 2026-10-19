<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantUserListingTest extends TestCase
{
    use IsolatesDatabase;

    private function loginAdmin(): User
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');

        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    public function test_users_index_returns_paginated_response(): void
    {
        $this->loginAdmin();

        $response = $this->getJson('/api/users?per_page=2')
            ->assertOk()
            ->assertJsonStructure([
                'users' => [
                    '*' => ['id', 'name', 'email', 'roles', 'is_default'],
                ],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertCount(2, $response->json('users'));
        $this->assertSame(1, $response->json('pagination.current_page'));
        $this->assertSame(2, $response->json('pagination.per_page'));
        $this->assertGreaterThanOrEqual(3, $response->json('pagination.total'));
    }

    public function test_users_can_be_filtered_by_search_query(): void
    {
        $this->loginAdmin();

        $response = $this->getJson('/api/users?q=editor')
            ->assertOk();

        $emails = collect($response->json('users'))->pluck('email');
        $this->assertTrue($emails->contains('editor@flowsync.test'));
        $this->assertFalse($emails->contains('admin@flowsync.test'));
        $this->assertFalse($emails->contains('viewer@flowsync.test'));
    }

    public function test_users_can_be_filtered_by_role(): void
    {
        $this->loginAdmin();

        $response = $this->getJson('/api/users?role=admin')
            ->assertOk();

        $users = collect($response->json('users'));
        $this->assertTrue($users->every(fn ($u) => in_array('admin', $u['roles'], true)));
        $this->assertTrue($users->pluck('email')->contains('admin@flowsync.test'));
        $this->assertFalse($users->pluck('email')->contains('viewer@flowsync.test'));
    }

    public function test_users_can_be_filtered_by_is_default(): void
    {
        $this->loginAdmin();

        $defaultResponse = $this->getJson('/api/users?is_default=true')
            ->assertOk();

        $defaultUsers = collect($defaultResponse->json('users'));
        $this->assertSame(1, $defaultUsers->count());
        $this->assertTrue($defaultUsers->first()['is_default']);

        $nonDefaultResponse = $this->getJson('/api/users?is_default=false')
            ->assertOk();

        $nonDefaultUsers = collect($nonDefaultResponse->json('users'));
        $this->assertTrue($nonDefaultUsers->every(fn ($u) => ! $u['is_default']));
    }

    public function test_users_index_all_flag_returns_unpaginated_list(): void
    {
        $this->loginAdmin();

        $response = $this->getJson('/api/users?all=1')
            ->assertOk();

        $users = collect($response->json('users'));
        $this->assertGreaterThanOrEqual(3, $users->count());
        $this->assertSame(1, $response->json('pagination.current_page'));
        $this->assertSame(1, $response->json('pagination.last_page'));
    }
}
